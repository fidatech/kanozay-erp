<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrmController extends Controller
{
    private function definition(string $page): array
    {
        $definition = config('crm_workflows.'.$page);
        abort_unless(is_array($definition), 404);

        return $definition;
    }

    private function authorizeAction(Request $request, string $ability): void
    {
        $admin = $ability === 'view' ? 'administration.view' : 'administration.update';
        abort_unless($request->user()?->canAny(['crm.'.$ability, 'sales.'.$ability, $admin]), 403);
    }

    private function options(string $source): array
    {
        $options = match ($source) {
            'customers' => DB::table('trade_parties')->where('type','customer')->where('is_active',true)->orderBy('name')->get(['id','name']),
            'leads' => DB::table('crm_leads')->whereNull('deleted_at')->orderBy('name')->get(['id','name']),
            'opportunities' => DB::table('crm_opportunities')->whereNull('deleted_at')->orderBy('title')->get(['id',DB::raw('title AS name')]),
            'segments' => DB::table('crm_segments')->whereNull('deleted_at')->where('is_active',true)->orderBy('name')->get(['id','name']),
            'currencies' => DB::table('currencies')->where('is_active',true)->orderBy('code')->get(['id',DB::raw('code AS name')]),
            'users' => User::query()->orderBy('name')->get(['id','name']),
            default => collect(),
        };

        return $options->mapWithKeys(fn ($record) => [(int) $record->id => (string) $record->name])->all();
    }

    private function lookupOptions(array $definition): array
    {
        $lookups = [];
        foreach ($definition['fields'] as $name => $definitionField) {
            if (($definitionField['type'] ?? '') === 'relation') {
                $lookups[$name] = $this->options($definitionField['source']);
            }
        }

        return $lookups;
    }

    public function index(Request $request, string $page)
    {
        $definition = $this->definition($page);
        $this->authorizeAction($request, 'view');

        $filters = $request->validate([
            'search' => ['nullable','string','max:100'],
            'export' => ['nullable',Rule::in(['csv'])],
            'edit' => ['nullable','integer','min:1'],
        ]);
        $query = DB::table($definition['table'])->whereNull('deleted_at');
        if (($search = trim((string)($filters['search'] ?? ''))) !== '') {
            $query->where(function ($q) use ($definition, $search) {
                foreach ($definition['search'] as $field) {
                    $q->orWhere($field, 'like', '%'.$search.'%');
                }
            });
        }

        $lookups = $this->lookupOptions($definition);
        $format = static function ($record, string $field) use ($lookups): string {
            $value = $record->{$field} ?? null;
            if ($value === null) return '';
            if (isset($lookups[$field])) return $lookups[$field][$value] ?? 'ID '.$value;
            return (string) $value;
        };

        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query,$definition,$format) {
                $out = fopen('php://output','w');
                fputcsv($out, array_map(fn ($field) => $definition['fields'][$field]['label'] ?? ucwords(str_replace('_',' ',$field)), $definition['columns']));
                (clone $query)->reorder('id')->chunkById(200, function ($records) use ($out,$definition,$format) {
                    foreach ($records as $record) {
                        $cells = [];
                        foreach ($definition['columns'] as $field) {
                            $value = $format($record,$field);
                            $cells[] = preg_match('/^\s*[=+\-@]/u',$value) ? "'".$value : $value;
                        }
                        fputcsv($out,$cells);
                    }
                });
                fclose($out);
            }, 'crm-'.Str::slug($page).'-'.now()->format('Ymd').'.csv',
            ['Content-Type'=>'text/csv; charset=UTF-8']);
        }

        $records = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $editRecord = null;
        if (isset($filters['edit'])) {
            $editRecord = DB::table($definition['table'])->whereNull('deleted_at')->where('id',$filters['edit'])->first();
            abort_unless($editRecord,404);
        }

        return view('crm.register',compact('page','definition','records','editRecord','lookups','filters','format'));
    }

    private function validated(Request $request, string $page): array
    {
        $definition = $this->definition($page);
        $rules = [];
        foreach ($definition['fields'] as $name => $field) {
            $type = $field['type'] ?? 'text';
            $required = $field['required'] ?? false;
            $itemRules = [$required ? 'required' : 'nullable'];
            if ($type === 'email') $itemRules = [...$itemRules,'email','max:180'];
            elseif ($type === 'textarea') $itemRules = [...$itemRules,'string','max:5000'];
            elseif ($type === 'money') $itemRules = [...$itemRules,'numeric','min:0','decimal:0,4','max:999999999999.9999'];
            elseif ($type === 'percentage') $itemRules = [...$itemRules,'integer','between:0,100'];
            elseif ($type === 'date') $itemRules[] = 'date';
            elseif ($type === 'datetime') $itemRules[] = 'date';
            elseif ($type === 'select') $itemRules[] = Rule::in(array_keys($field['options']));
            elseif ($type === 'relation') {
                $table = match ($field['source']) {
                    'users' => 'users', 'customers'=>'trade_parties', 'segments'=>'crm_segments',
                    'leads'=>'crm_leads', 'opportunities'=>'crm_opportunities', 'currencies'=>'currencies',
                };
                $exists = Rule::exists($table,'id');
                if ($field['source']==='customers') $exists = $exists->where('type','customer')->where('is_active',true);
                elseif (in_array($field['source'],['leads','opportunities','segments'],true)) $exists = $exists->whereNull('deleted_at');
                elseif ($field['source']==='currencies') $exists = $exists->where('is_active',true);
                $itemRules = [...$itemRules,'integer',$exists];
            } else $itemRules = [...$itemRules,'string','max:180'];
            $rules[$name] = $itemRules;
        }

        $data = $request->validate($rules);
        foreach ($definition['fields'] as $name => $field) {
            if (($field['type'] ?? null)==='datetime' && !empty($data[$name])) {
                $data[$name] = Carbon::parse($data[$name])->format('Y-m-d H:i:s');
            }
            if (isset($data[$name]) && is_string($data[$name])) {
                $data[$name] = trim($data[$name]);
            }
        }
        foreach ($definition['fields'] as $name=>$field) {
            if (!array_key_exists($name,$data) && isset($field['default'])) $data[$name] = $field['default'];
        }

        return $data;
    }

    public function store(Request $request, string $page)
    {
        $definition = $this->definition($page);
        $this->authorizeAction($request,'create');
        $data = $this->validated($request,$page);
        $id = DB::transaction(function () use ($definition,$data,$request,$page) {
            $now = now();
            $data['created_by'] = $request->user()->id;
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            if (isset($definition['prefix'])) {
                $data['number'] = 'TMP-'.Str::uuid();
            }
            $id = DB::table($definition['table'])->insertGetId($data);
            if (isset($definition['prefix'])) {
                DB::table($definition['table'])->where('id',$id)
                    ->update(['number'=>$definition['prefix'].'-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
            }
            ActivityLog::record('crm.'.$page.'.created','crm_'.$page,$id,'Created CRM '.$page.' record.');
            return $id;
        });

        return redirect()->route('workspace.page',['crm',$page])->with('success','Record created successfully (#'.$id.').');
    }

    public function update(Request $request,string $page,int $record)
    {
        $definition = $this->definition($page);
        $this->authorizeAction($request,'update');
        $data = $this->validated($request,$page);

        DB::transaction(function () use ($definition,$record,$data,$page) {
            $query = DB::table($definition['table'])->whereNull('deleted_at')->where('id',$record);
            $existing = (clone $query)->lockForUpdate()->first();
            abort_unless($existing,404);
            $before = [];
            foreach ($data as $field=>$value) {
                if ((string)($existing->{$field} ?? '') !== (string)($value ?? '')) {
                    $before[$field] = $existing->{$field} ?? null;
                }
            }
            if ($before) {
                (clone $query)->update($data+['updated_at'=>now()]);
                ActivityLog::record('crm.'.$page.'.updated','crm_'.$page,$record,'Updated CRM '.$page.' record.',[
                    'previous'=>$before,'changed_fields'=>array_keys($before),
                ]);
            }
        });

        return redirect()->route('workspace.page',['crm',$page])->with('success','Record updated.');
    }

    public function destroy(Request $request,string $page,int $record)
    {
        $definition = $this->definition($page);
        $this->authorizeAction($request,'delete');
        DB::transaction(function () use ($definition,$record,$page) {
            $query = DB::table($definition['table'])->whereNull('deleted_at')->where('id',$record);
            abort_unless((clone $query)->lockForUpdate()->exists(),404);
            (clone $query)->update(['deleted_at'=>now(),'updated_at'=>now()]);
            ActivityLog::record('crm.'.$page.'.archived','crm_'.$page,$record,'Archived CRM '.$page.' record.');
        });

        return redirect()->route('workspace.page',['crm',$page])->with('success','Record archived. Existing history is preserved.');
    }
}
