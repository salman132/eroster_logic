<?php


namespace App\Http\Controllers;
use App\AssignAgentToProject;
use App\AssignSeatToProject;
use App\Employee;
use App\EmployeeRolesPermission;
use App\lateOvertime;
use App\Leave;
use App\Obligation;
use App\Project;
use App\Roster;
use App\RosterInfo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RosterController extends Controller
{

    public $previous_shift;
    public function index(){

        $role_id = Auth::user()->role_id;

        $employee_permission = EmployeeRolesPermission::where('role_id',$role_id)->where('perm_id',130)->get();

        if(count($employee_permission) < 1){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }

        $all_permission= EmployeeRolesPermission::where('role_id',$role_id)->get();

        $today = Carbon::today()->toDateString();
        $projects = Project::where('status',1)->where('ends_at','>=',$today)->get();
        $rosters = Roster::all();

        return view('admin.roaster.roster.index',compact('all_permission','projects','rosters'));
    }

    public function store(Request $request){

        $this->validate($request,[
            'project_id'=> 'required|integer|min:1',
            'month_name' => 'required|date_format:m',
            'year'=> 'required|date_format:Y',
        ]);

        $roster_availability = Roster::where('year',$request->year)->where('month',$request->month_name)->first();

        if(!empty($roster_availability)){
            return redirect()->back()->with([
                "message" => "Roster is already initiated on this {$request->year} - {$request->month_name} date",
                "message_important" => true
            ]);
        }



        $project = Project::findOrfail($request->project_id);


        // Checking if seat exists for project
        if(empty($project->seat->id)) {
            return redirect()->route('assign.project-seat')->with([
                "message" => "No Seat Available for this {$project->project_name}. Add Some First",
                "message_important" => true
            ]);
        }
        // Checking if agent exists for project
        if(empty($project->assigned_project->agent_id)){
            return redirect()->route('p.add_agent')->with([
                "message" => "No Agent Available for this {$project->project_name}. Add Some First",
                "message_important" => true
            ]);
        }



        // Checking if shifts exists for project

        $shift_lists = array();
        $female_allownce = false;

        foreach ($project->shift as $shift){
            $shift_lists[] = $shift->project_id;

            //Checking if the female is allowed for this shift
            if($shift->female_allowed ==1){
                $female_allownce = true;
            }

        }
        if(empty($shift_lists) || count($shift_lists) == 0){
             return redirect()->route('eroster.shift')->with([
                  "message" => "No Shifts Available for this {$project->project_name}. Add Some First",
                  "message_important" => true
             ]);
        }

        //After all condition, we will store data

        $roster = new Roster();
        $roster->project_id = $request->project_id;
        $roster->year = $request->year;
        $roster->month = $request->month_name;
        $roster->created_by = Auth::id();

        $roster->save();

        // Getting Vacation Lists

        $weekend = lateOvertime::all(['weekend']);

        $weekend_lists = array();

        foreach ($weekend as $week){
            $weekend_lists[] = explode(",",$week->weekend);
        }


        $arr =  array_flatt($weekend_lists);
        $n = sizeof($arr);
        $k = 8;
        // Got Common Weekend
        $common_weekend = maxRepeating($arr, $n, $k);

        if($common_weekend ==0){$common_weekend = "Sunday";}
        if($common_weekend ==1){$common_weekend = "Monday";}
        if($common_weekend ==2){$common_weekend = "Tuesday";}
        if($common_weekend ==3){$common_weekend = "Wednesday";}
        if($common_weekend ==4){$common_weekend = "Thursday";}
        if($common_weekend ==5){$common_weekend = "Friday";}
        if($common_weekend ==6){$common_weekend = "Saturday";}

        // Getting 1 Official Off day Start
        $off_day = array();
        $collection_of_off_day = array();


        $date = date($request->year.'-'.$request->month_name);
        $day= $common_weekend;
        $off_day[0] = date('d',strtotime("first {$day} of ".$date));
        $off_day[1] = $off_day[0] + 7;
        $off_day[2] =  $off_day[0] + 14;
        $off_day[3] =  $off_day[0] + 21;
        $off_day['last'] = date('d',strtotime("last {$day} of ".$date));

        if($off_day[3] == $off_day['last']){
            unset($off_day['last']);
        }
        else {
            $off_day[4] = $off_day['last'];
            unset($off_day['last']);
        }

        foreach($off_day as $off){
            $collection_of_off_day[] = date('Y-m-d',strtotime($date."-".$off));

        }

        $lists = array(); // Working Able Days
        for($d=1; $d<=31; $d++)
        {
            $time=mktime(12, 0, 0, $request->month_name, $d, $request->year);
            if (date('m', $time)==$request->month_name) {
                $lists[] = date('Y-m-d', $time);
            }
        }



        foreach ($lists as $key=>$list){
            if(in_array($list,$collection_of_off_day)){

                unset($lists[$key]);
            }
        }

        // Getting 1 Official Off day ENds

       // Getting All agents


        if(!empty($project->assigned_project->agent_id)) {

            $agents_id = explode(",", $project->assigned_project->agent_id);




        }
        else{$agents_id = NULL;}

        $shift_array = array();

        foreach ($project->shift as $key=> $shift){
            $shift_array[] = $shift->id;
        }




        $seats = explode(",",$project->seat->seat_id);
        $previous_shift = [];
        foreach ($lists as $list) {


                foreach ($seats as $seat) {
                    foreach ($agents_id as $agent) {
                        foreach ($project->shift as $key=> $shift){

                        if ($female_allownce) {

                            $employee = Employee::where('id', $agent)->first();

                        } else {
                            $employee = Employee::where('id', $agent)->where('gender', '<>', 'Female')->first();
                        }


                        //Finding Obligation of the agents

                        $obligged_agent = array();
                        // Getting obligation date lists of agents

                        //If agents has any obligation
                        $obligation = Obligation::where('obligation_date', $list)->where('agent_id', $agent)->first();

                        if (!empty($obligation)) {
                            if ($list == $obligation->obligation_date) {
                                $obligation_date = $obligation->obligation_date;
                                foreach ($project->shift as $busy) {
                                    $startTime = $busy->service_start;
                                    $endTime = $busy->service_end;


                                    $assigned_agent = Obligation::where('obligation_date', $obligation_date)
                                        ->where(function ($dateQuery) use ($startTime, $endTime) {

                                            $dateQuery->where(function ($query) use ($startTime, $endTime) {
                                                $query->where(function ($q) use ($startTime, $endTime) {
                                                    $q->where('obligation_from', '>=', $startTime)
                                                        ->where('obligation_to', '<=', $endTime);
                                                })
                                                    ->orWhere(function ($q) use ($startTime, $endTime) {
                                                        $q->where('obligation_from', '<=', $startTime)
                                                            ->where('obligation_to', '>=', $startTime);
                                                    });
                                            })
                                                ->orwhere(function ($query) use ($startTime, $endTime) {
                                                    $query->where(function ($q) use ($startTime, $endTime) {
                                                        $q->where('obligation_from', '>', $startTime)->where('obligation_to', '<', $endTime);
                                                    })
                                                        ->orWhere(function ($q) use ($startTime, $endTime) {
                                                            $q->where('obligation_from', '<=', $endTime)->where('obligation_to', '>', $endTime);
                                                        });
                                                });
                                        })->first();



                                        if (!empty($assigned_agent)){
                                            if ($female_allownce) {

                                                $employee = Employee::where('id','<>', $assigned_agent->agent_id)->where('role_id',6)->first();

                                            } else {

                                                $employee = Employee::where('id','<>' ,$assigned_agent->agent_id)->where('role_id',6)->where('gender', '<>', 'Female')->first();
                                            }
                                        }

                                    }
                                // End of foreach

                            }

                        }


                        if (!empty($employee)) {

                            $roster_info_check1=RosterInfo::whereDate('date_list',$list)->Where(function($q)use($shift,$seat){
                                $q->where('shift_id',$shift->id)->where('seat_id',$seat);})->count();
                            $roster_info_check2=RosterInfo::whereDate('date_list',$list)->Where(function($q)use($shift,$employee){
                                $q->where('shift_id',$shift->id)->where('agent_id',$employee->id);})->count();
                            $roster_agent = RosterInfo::where('date_list',$list)->where('agent_id',$employee->id)->count();

                            if ($roster_info_check1==0 && $roster_info_check2==0 && $roster_agent==0){
                                $roster_info = new RosterInfo();
                                $roster_info->roster_id = $roster->id;
                                $roster_info->shift_id = $shift->id;
                                $roster_info->seat_id = $seat;
                                $roster_info->agent_id = $employee->id;
                                $roster_info->date_list = $list;
                                $roster_info->save();
                            }


                        }

                    }
                }
            }




        }


    }

}
