<?php
namespace App\Http\Controllers;

use App\Models\EmployeeProfile;
use App\Models\OperationalRegister;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrShiftAttendanceReviewController extends Controller
{
 public function index(Request $request)
 {
  abort_unless($request->user()->canAny(['hr.view','administration.view']),403);
  $filters=$request->validate([
   'month'=>['nullable','date_format:Y-m'],
   'employee_id'=>['nullable','integer','exists:employee_profiles,id'],
  ]);
  $month=$filters['month']??now()->format('Y-m');
  $start=CarbonImmutable::parse($month.'-01')->startOfMonth();
  $end=$start->endOfMonth()->toDateString();
  $query=OperationalRegister::where('workflow','hr.shifts')
   ->whereIn('status',['approved','closed'])
   ->whereDate('created_at','<=',now()->toDateString());
  // Data JSON is authoritative for the shift date; no payroll modifications.
  $shiftRows=$query->get()->filter(function($row)use($start,$end,$filters){
    $day=$row->data['shift_date']??'';
    if($day<$start->toDateString()||$day>$end)return false;
    return !isset($filters['employee_id']) ||
     (int)($row->data['employee']??0)===(int)$filters['employee_id'];
  })->sortBy(fn($row)=>($row->data['shift_date']??'').'|'.($row->data['employee']??''));
  $ids=$shiftRows->pluck('data')->map(fn($v)=>$v['employee']??null)->filter()->unique()->values();
  $employees=EmployeeProfile::whereIn('id',$ids)->get()->keyBy('id');
  $attendance=DB::table('employee_attendance')
   ->whereBetween('attendance_date',[$start->toDateString(),$end])
   ->whereIn('employee_profile_id',$ids)->get()
   ->keyBy(fn($r)=>$r->employee_profile_id.'|'.substr($r->attendance_date,0,10));
  $rows=$shiftRows->map(function($shift)use($employees,$attendance){
    $data=$shift->data;
    $employee=$employees->get($data['employee']??0);
    $record=$attendance->get(($data['employee']??'').'|'.$data['shift_date']);
    return [
     'id'=>$shift->id,'number'=>$shift->number,
     'employee_number'=>$employee?->employee_number??'—',
     'employee_name'=>$employee?->full_name??'Unknown employee',
     'date'=>$data['shift_date'],'starts_at'=>$data['starts_at'],
     'ends_at'=>$data['ends_at'],'branch'=>$data['branch']??null,
     'attendance_status'=>$record?->status??'not_recorded',
     'attendance_id'=>$record?->id,
     'shift_status'=>$shift->status,
    ];
  })->values();
  $counts=['planned'=>$rows->count(),'present'=>$rows->where('attendance_status','present')->count(),
    'absent'=>$rows->where('attendance_status','absent')->count(),
    'leave'=>$rows->where('attendance_status','leave')->count(),
    'holiday'=>$rows->where('attendance_status','holiday')->count(),
    'not_recorded'=>$rows->where('attendance_status','not_recorded')->count(),
  ];
  return view('hr.shift-attendance-review',[
   'month'=>$month,'employees'=>EmployeeProfile::where('is_active',true)->orderBy('employee_number')->get(),
   'selectedEmployee'=>$filters['employee_id']??null,
   'rows'=>$rows,'counts'=>$counts,
  ]);
 }
 public function export(Request $request)
 {
  abort_unless($request->user()->canAny(['hr.view','administration.view']),403);
  $response=$this->index($request);
  $rows=$response->getData()['rows'];
  return response()->streamDownload(function()use($rows){
   $out=fopen('php://output','w');fputcsv($out,['Shift','Employee Number','Employee','Date','Start','Finish','Attendance','Shift Approval']);
   foreach($rows as $row){
    $cells=[$row['number'],$row['employee_number'],$row['employee_name'],$row['date'],
     $row['starts_at'],$row['ends_at'],$row['attendance_status'],$row['shift_status']];
    fputcsv($out,array_map(fn($s)=>preg_match('/^\s*[=+\-@]/u',(string)$s)?"'".$s:$s,$cells));
   }
   fclose($out);
  },'kanozay-shift-attendance-'.$response->getData()['month'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}