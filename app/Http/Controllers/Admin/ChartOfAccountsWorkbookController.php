<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChartOfAccountsWorkbookController extends Controller
{
    private const HEADERS = ['code','name','type','parent_code','kind','status','description'];
    private const TYPES = ['asset','liability','equity','income','expense'];

    public function sample(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('administration.view'), 403);
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Chart of Accounts');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray([
            ['UAT-1000','Example Assets','asset','','group','active','Replace sample codes and names'],
            ['UAT-1100','Example Cash','asset','UAT-1000','posting','active','Child of Example Assets'],
        ], null, 'A2');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('174B82');
        $sheet->freezePane('A2');$sheet->setAutoFilter('A1:G3');
        foreach (['A'=>19,'B'=>26,'C'=>18,'D'=>21,'E'=>17,'F'=>16,'G'=>43] as $col=>$width) $sheet->getColumnDimension($col)->setWidth($width);
        $instructions = $book->createSheet()->setTitle('Instructions');
        $instructions->fromArray([
            ['Chart of Accounts — Import instructions'],
            ['Use the Chart of Accounts sheet. Headers must remain unchanged.'],
            ['code: unique account identifier (letters, numbers, dot or dash; max 24).'],
            ['name: account name (max 160).'],
            ['type: asset, liability, equity, income or expense.'],
            ['parent_code: blank for a root; otherwise code of an existing group or a group in this file.'],
            ['kind: group or posting. A posting account cannot have children.'],
            ['status: active or inactive.'],
            ['description: optional, max 500 characters.'],
            ['Replace/remove the UAT sample rows before importing production accounts.'],
            ['Upload creates missing chart accounts only. It never imports balances or journal entries.'],
            ['An existing account code is treated as a duplicate and causes import validation to fail.'],
        ],null,'A1');
        $instructions->getColumnDimension('A')->setWidth(115);
        $path = tempnam(sys_get_temp_dir(),'kanozay-coa-template-');
        try {(new Xlsx($book))->save($path);} finally {$book->disconnectWorksheets();}
        return response()->download($path,'Kanozay-COA-Import-Template.xlsx',[
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function import(Request $request)
    {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['file'=>['required','file','mimes:xlsx','max:4096']]);
        $reader=IOFactory::createReaderForFile($data['file']->getRealPath());
        $reader->setReadDataOnly(true);
        $book=$reader->load($data['file']->getRealPath());
        try {
            if (!$book->sheetNameExists('Chart of Accounts')) throw ValidationException::withMessages(['file'=>'Worksheet "Chart of Accounts" is required. Download the template.']);
            $sheet=$book->getSheetByName('Chart of Accounts');
            if ($sheet->getHighestDataRow()>1001 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn())>7)
                throw ValidationException::withMessages(['file'=>'A workbook can contain at most 1,000 account rows and seven columns.']);
            $headers=array_map(fn($value)=>strtolower(trim((string)($value??''))),$sheet->rangeToArray('A1:G1',null,false,false)[0]);
            if ($headers!==self::HEADERS) throw ValidationException::withMessages(['file'=>'Template headers do not match. Download the latest sample.']);
            $rows=[];
            for($i=2;$i<=$sheet->getHighestDataRow();$i++){
                $values=array_map(fn($value)=>trim((string)($value??'')),$sheet->rangeToArray("A{$i}:G{$i}",null,false,false)[0]);
                if(implode('',$values)==='')continue;
                $row=array_combine(self::HEADERS,$values);
                $row['type']=strtolower($row['type']);$row['kind']=strtolower($row['kind']);$row['status']=strtolower($row['status']);
                if(!preg_match('/^[A-Za-z0-9.-]{1,24}$/D',$row['code']) || !strlen($row['name']) || mb_strlen($row['name'])>160 || !in_array($row['type'],self::TYPES,true) || !in_array($row['kind'],['group','posting'],true) || !in_array($row['status'],['active','inactive'],true) || mb_strlen($row['description'])>500)
                    throw ValidationException::withMessages(['file'=>"Invalid account values on worksheet row {$i}."]);
                $row['sheet_row']=$i;
                if(isset($rows[$row['code']]))throw ValidationException::withMessages(['file'=>"Repeated account code {$row['code']} on row {$i}."]);
                $rows[$row['code']]=$row;
            }
            if(!$rows) throw ValidationException::withMessages(['file'=>'No accounts found in the workbook.']);
            // Resolve hierarchy as a graph and check the entire batch before inserting anything.
            $existing=LedgerAccount::whereIn('code',array_unique(array_merge(array_keys($rows),array_filter(array_column($rows,'parent_code')))))->get()->keyBy('code');
            foreach($rows as $code=>$row){
                if($existing->has($code))throw ValidationException::withMessages(['file'=>"Account {$code} already exists. No accounts were imported."]);
                if($row['parent_code']!=='' && !isset($rows[$row['parent_code']]) && !$existing->has($row['parent_code']))throw ValidationException::withMessages(['file'=>"Parent {$row['parent_code']} not found for {$code}."]);
                if($row['parent_code']!==''){
                    $parent=$rows[$row['parent_code']]??null;
                    $group=$parent ? $parent['kind']==='group' : $existing[$row['parent_code']]->is_group;
                    $active=$parent ? $parent['status']==='active' : $existing[$row['parent_code']]->is_active;
                    $type=$parent ? $parent['type'] : $existing[$row['parent_code']]->type;
                    if(!$group || !$active || $type!==$row['type'])throw ValidationException::withMessages(['file'=>"Parent {$row['parent_code']} must be an active group of the same account type."]);
                }
                if($row['status']==='inactive' && count(array_filter($rows,fn($child)=>$child['parent_code']===$code))>0)throw ValidationException::withMessages(['file'=>"Group {$code} has children and cannot be inactive."]);
            }
            $ordered=[];$visiting=[];
            $visit=function(string $code) use (&$visit,&$ordered,&$visiting,$rows){
                if(isset($ordered[$code]))return;
                if(isset($visiting[$code]))throw ValidationException::withMessages(['file'=>"Parent cycle detected for {$code}."]);
                $visiting[$code]=true;
                $parent=$rows[$code]['parent_code'];if($parent!==''&&isset($rows[$parent]))$visit($parent);
                unset($visiting[$code]);$ordered[$code]=$rows[$code];
            };
            foreach(array_keys($rows) as $code)$visit($code);
            $created=DB::transaction(function()use($ordered,$request,$existing){
                $ids=$existing->mapWithKeys(fn($item)=>[$item->code=>$item->id])->all();
                foreach($ordered as $code=>$row){
                    if(LedgerAccount::where('code',$code)->exists())throw ValidationException::withMessages(['file'=>"Account {$code} was created by another request. Retry with a revised workbook."]);
                    $account=LedgerAccount::create(['code'=>$code,'name'=>$row['name'],'type'=>$row['type'],'parent_id'=>$row['parent_code']!==''?$ids[$row['parent_code']]:null,'is_group'=>$row['kind']==='group','is_active'=>$row['status']==='active','description'=>$row['description']?:null,'created_by'=>$request->user()->id]);
                    $ids[$code]=$account->id;
                }
                ActivityLog::record('account.workbook_imported','ledger_account',null,'Imported '.count($ordered).' chart of accounts from validated workbook',['count'=>count($ordered)]);
                return count($ordered);
            });
            return redirect()->route('admin.accounts.index')->with('status',"{$created} chart accounts imported successfully. No balances or journals were created.");
        } finally {$book->disconnectWorksheets();}
    }
}
