<?php



namespace App\Http\Controllers;
ini_set('max_execution_time', '0'); // for infinite time of execution
ini_set("memory_limit",-1);
set_time_limit(0);
use App\AssignAgentToProject;
use App\AssignSeatToProject;
use App\Department;
use App\Employee;
use App\EmployeeRolesPermission;
use App\lateOvertime;
use App\Leave;
use App\Obligation;
use App\Project;
use App\Roster;
use App\RosterEmployeeHoliday;
use App\RosterInfo;
use App\Seat;
use App\Shift;
use App\Team;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RosterController extends Controller
{


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
        $rosters = Roster::orderBY('created_at','DESC')->paginate(10);

        return view('admin.roaster.roster.index',compact('all_permission','projects','rosters'));
    }

    public function view(Request $request,$id){
        $role_id = Auth::user()->role_id;

        $employee_permission = EmployeeRolesPermission::where('role_id',$role_id)->where('perm_id',130)->get();

        if(count($employee_permission) < 1){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }

        $roster_infos = RosterInfo::where('roster_id',$id)->groupBy('date_list');
        //For Customizing the page
        $custom = false;
        $searched_agent= 0;
        $searched_shift =0;
        
        if(!empty($request->agent) || !empty($request->shift) || !empty($request->from) || !empty($request->to)){
             $custom = true;
             $roster_infos = RosterInfo::where('roster_id',$id);
        }

        if(!empty($request->agent)){
            $roster_infos = $roster_infos
                ->where('agent_id',$request->agent);
           
            $searched_agent = $request->agent;
        }
        
        
        

        if(!empty($request->shift)){
           
            $roster_infos = $roster_infos
                ->where('shift_id',$request->shift);
         
            $searched_shift = $request->shift;
        }
        
      
        if(!empty($request->from) && !empty($request->to)){
            
            $roster_infos = $roster_infos
                ->whereBetween('date_list',[$request->from,$request->to]);
            
        }
        


        $roster_infos = $roster_infos->get();

        $roster = Roster::findOrFail($id);
        $project = Project::findOrFail($roster->project_id);
        $agents = !empty($project->assigned_project->agent_id) ? explode(",", $project->assigned_project->agent_id) : null;
        $agents = Employee::whereIn('id',$agents)->get(['fname','lname','id']);

        $roster_shifts = $project->shift;

        $seats = explode(",",$project->seat->seat_id);

        $seats = Seat::whereIN('id',$seats)->get();



        return view('admin.roaster.roster.view',compact('roster_infos','roster','seats',
            'roster_shifts','agents','custom','searched_agent','searched_shift'));






    }

    public function update(Request $request,$id){
        //Swap type 1 = Agent Swap , 2= Force Swap
        $swap_type = (int)$request->swap_type;
        if($swap_type ==1){
            $swap_type = 1;
        }
        if($swap_type ==2){
            $swap_type =2;
        }
        else{
            $swap_type = 1;
        }

        if($swap_type ==1){
            $this->validate($request,[
                'old_roster_id'=> 'required|integer|min:1',
                'date'=> 'required|date_format:Y-m-d',
                'seat_id'=> 'required|integer|min:1',
                'shift_id'=> 'required|integer|min:1',
            ]);


            $old_rosterinfo = RosterInfo::findOrFail($request->old_roster_id);
            $old_agent = $old_rosterinfo->agent_id;



            $new_rosterinfo = RosterInfo::where('roster_id',$id)
                ->whereDate('date_list',$request->date)
                ->where('seat_id',$request->seat_id)
                ->where('shift_id',$request->shift_id)->first();


            $new_agent = $new_rosterinfo->agent_id;



            // Updating old agent
            $roster_swap_first = RosterInfo::findOrFail($old_rosterinfo->id);
            $roster_swap_first->agent_id = $new_agent;
            $roster_swap_first->save();


            // Updating new agent
            $roster_swap_second = RosterInfo::findOrFail($new_rosterinfo->id);
            $roster_swap_second->agent_id = $old_agent;
            $roster_swap_second->save();




        }
        if($swap_type == 2){
            $this->validate($request,[
                'old_roster_id'=> 'required|integer|min:1',
                'force_date'=> 'required|date_format:Y-m-d',
                'force_seat_id'=> 'required|integer|min:1',
                'force_shift_id'=> 'required|integer|min:1',
            ]);
            $roster_info = RosterInfo::find($request->old_roster_id);

            $roster_info->seat_id = $request->force_seat_id;
            $roster_info->shift_id = $request->force_shift_id;
            $roster_info->date_list = $request->force_date;
            $roster_info->save();
        }








        return redirect()->back()->with([
            "message" => "Roster Swapped Successfully",
        ]);




    }

    public function date_wise(){
        $role_id = Auth::user()->role_id;

        $employee_permission = EmployeeRolesPermission::where('role_id',$role_id)->where('perm_id',130)->get();

        if(count($employee_permission) < 1){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }

        return view('admin.roaster.roster.datewise');

    }


    public function store(Request $request){


        //Roster type 1 = Weekly , 2 = Monthly / except 1 everything is monthly

        $roster_type  = (int)$request->roster_type;

        if($roster_type ==1){
            $this->validate($request,[
                'project_id'=> 'required|integer|min:1',
                'roster_type' => 'required|integer|min:1',
                'week_start' => 'required|date_format:Y-m-d',
                'week_end' => 'required|date_format:Y-m-d',

            ]);
        }
        else{
            $this->validate($request,[
                'project_id'=> 'required|integer|min:1',
                'month_name' => 'required|date_format:m',
                'year'=> 'required|date_format:Y',
            ]);
        }


        if($roster_type !=1){
            $roster_availability = Roster::where('year',$request->year)
                ->where('project_id',$request->project_id)
                ->where('month',$request->month_name)->first();

            if(!empty($roster_availability)){
                return redirect()->back()->with([
                    "message" => "Roster is already initiated on this {$request->year}-{$request->month_name} date for this project",
                    "message_important" => true
                ]);
            }

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
        $roster->roster_type = $roster_type;
        if($roster_type ==1){
            $roster->year = date('Y',strtotime($request->week_start));
            $roster->month = date('m',strtotime($request->week_start));
            $roster->weekly_start = $request->week_start;
            $roster->weekly_end = $request->week_end;
        }
        else{
            $roster->year = $request->year;
            $roster->month = $request->month_name;
        }

        $roster->created_by = Auth::id();

        $roster->save();


        // Getting All agents

        $agents_id = !empty($project->assigned_project->agent_id) ? explode(",", $project->assigned_project->agent_id) : null;

        $seats = explode(",",$project->seat->seat_id);

        //Getting All days excluding off days
        $lists = $this->getting_all_days($roster_type,$roster,$request->all());







        foreach ($lists as  $list) {
            $seats = collect($seats);
            $seats_chunked = $seats->chunk(5);
            foreach ($seats_chunked as $seats) {
                foreach ($seats as $seat){

                    $agents_id_chunked = collect($agents_id);
                    $agents_id_chunked = $agents_id_chunked->chunk(2);

                    foreach ($agents_id_chunked as $agent_id){
                        foreach ($agent_id as $agent) {
                            $shift_chunked = $project->shift->chunk(2);
                            foreach ($shift_chunked as $shifts){
                                foreach ($shifts as $shift) {

                                    if ($shift->female_allowed == 1) {
                                        $female_allownce = true;
                                    }
                                    if ($shift->female_allowed == 0) {
                                        $female_allownce = false;
                                    }
                                    if ($female_allownce == true) {

                                        $employee = Employee::where('id', $agent)->first(['id']);

                                    }
                                    if ($female_allownce == false) {
                                        $employee = Employee::where('id', $agent)->where('gender', '<>', 'Female')->first(['id']);
                                    }




                                    //If agents has any obligation
                                    $obligated_agent = $this->obligation($list,$agent,$shift);



                                    //If agent has any holidays
                                    $holidays = $this->days_of_holiday($list,$agent);

                                    if (!empty($employee)) {


                                        $roster_info_check1 = RosterInfo::where('roster_id',$roster->id)->whereDate('date_list', $list)
                                            ->Where(function ($q) use ($shift, $seat) {
                                                $q->where('shift_id', $shift->id)->where('seat_id', $seat);
                                            })->count();
                                        $roster_info_check2 = RosterInfo::where('roster_id',$roster->id)->whereDate('date_list', $list)->Where(function ($q) use ($shift, $employee) {
                                            $q->where('shift_id', $shift->id)->where('agent_id', $employee->id);
                                        })->count();
                                        $roster_agent = RosterInfo::where('roster_id',$roster->id)->where('date_list', $list)->where('agent_id', $employee->id)->count();

                                        if ($roster_info_check1 == 0 && $roster_info_check2 == 0 && $roster_agent == 0  && $holidays==0 && $obligated_agent ==0) {


                                            RosterInfo::create([
                                                'roster_id' =>$roster->id,
                                                'shift_id' => $shift->id,
                                                'seat_id' => $seat,
                                                'agent_id' => $employee->id,
                                                'date_list' => $list

                                            ]);
                                        }


                                    }

                                }
                            }

                        }
                    }

                }


            }




        }




        return redirect()->back()->with([
            "message" => "Roster Generated Successfully",
        ]);


    }

    public function find_seat(Request $request){

        $this->validate($request,[
            'roster_id'=>'required|integer|min:1',
            'date'=> 'required|date_format:Y-m-d',
        ]);


        if($request->ajax()){
            $roster = RosterInfo::where('roster_id',$request->roster_id)
                ->where('date_list',$request->date)
                ->groupBy('seat_id')
                ->get();
            if(count($roster)>0){
                return view('admin.roaster.roster.ajax.agent_swapping',compact('roster'))->render();
            }
            else{
                return "No Data Found";
            }
        }
    }


    public function find_shift(Request $request){
        $this->validate($request,[
            'roster_id'=>'required|integer|min:1',
            'date'=> 'required|date_format:Y-m-d',
        ]);


        if($request->ajax()){
            $roster = RosterInfo::where('roster_id',$request->roster_id)->where('date_list',$request->date)->groupBy('shift_id')->get();
            if(count($roster)>0){
                return view('admin.roaster.roster.ajax.find_shift',compact('roster'))->render();
            }
            else{
                return "No Data Found";
            }
        }
    }



    public function find_agent(Request $request){
        $this->validate($request,[
            'roster_id'=>'required|integer|min:1',
            'date'=> 'required|date_format:Y-m-d',
            'seat_id'=> 'required|integer|min:1',
            'shift_id' => 'required|integer|min:1'
        ]);

        if($request->ajax()){
            $roster = RosterInfo::where('roster_id',$request->roster_id)
                ->where('date_list',$request->date)
                ->where('seat_id',$request->seat_id)
                ->where('shift_id',$request->shift_id)->first();

            return view('admin.roaster.roster.ajax.find_agent',compact('roster'))->render();

        }
    }


    public function destroy(Request $request){
        $this->validate($request,[
            'roster_id' => 'required|integer|min:1'
        ]);



        $roster_info = RosterInfo::where('roster_id',$request->roster_id)->delete();

        $roster = Roster::where('id',$request->roster_id)->delete();



        return redirect()->back()->with([
            'message' => 'Data deleted Successfully'
        ]);

    }

    public function custom_agent_delete(Request $request){
        $this->validate($request,[
            'roster_id'=>'integer|min:1'
        ]);

        RosterInfo::destroy($request->roster_id);
        return redirect()->back()->with([
            'message' => 'Data deleted Successfully'
        ]);
    }

    public function export($id){

        $roster_infos = RosterInfo::where('roster_id',$id)->get();

        $roster = Roster::findOrFail($id);

        $agent = $_GET['agent'];
        $shift = $_GET['shift'];
        $from = $_GET['from'];
        $to = $_GET['to'];

        if(!empty($agent)){
            $roster_infos = RosterInfo::where('roster_id',$id)
                ->where('agent_id',$agent);

        }
        

        if(!empty($shift)){
           
            $roster_infos = $roster_infos
                ->where('shift_id',$shift);


        }
        if(!empty($from) && !empty($to)){
            
            $roster_infos = $roster_infos
                ->whereBetween('date_list',[$from,$to]);
        }


        $roster_infos = $roster_infos->get();



        $dataArray = [];

        foreach ($roster_infos as $info){
            $dataArray[$info->id]=[
                'Date' => date('d F Y',strtotime(date($info->date_list))),
                'seat' => $info->seat->seat_id,
                'shift' => $info->shift->service_start . "-". $info->shift->service_end . '( '.$info->shift->shift_title . ' )',
                'Agent Name'=> $info->agent->fname . ' '.$info->agent->lname ,

            ];
        }

        $date = $roster->year ."-".$roster->month;

        Excel::create(' Roster Information-'.date('Y-F',strtotime($date)), function($excel) use ($dataArray) {

            $excel->sheet('First Sheet', function($sheet) use ($dataArray){
                $sheet->cell('A1:N1', function($cell) {$cell->setFontWeight('bold')->setBackground('#AAAAFF');   });
                $sheet->fromArray($dataArray);
            });

        })->export('xlsx');




    }



    public function leave(){
        $permission = permission(Auth::user()->role_id,170);
        if($permission == false){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }

        $departments = Department::where('status',1)->get();
        $holidays = RosterEmployeeHoliday::all();


        return view('admin.roaster.holiday.index',compact('departments','holidays'));
    }

    public function department_wise(Request $request,$id){
        if($request->ajax()){
            $users = Employee::where('status','active')
                ->where('role_id',6)
                ->where('department',$id)->get();
            return response()->json($users);
        }
    }

    public function team_wise(Request $request,$dept_id){
        if($request->ajax()){
            $team = Team::where('dep_id',$dept_id)->get();
            return response()->json($team);
        }
    }

    public function team_wise_employee(Request $request,$team_id){
        if($request->ajax()){
            $employee = Employee::where('status','active')->where('role_id',6)
                ->where('team_id',$team_id)->get();


            return response()->json($employee);
        }
    }

    public function post_holiday(Request $request){
        $permission = permission(Auth::user()->role_id,170);
        if($permission == false){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }
        $this->validate($request,[
            'department_id'=> 'required|integer|min:1',
            'team_id'=> 'nullable|integer|min:0',
            'emp_id'=> 'required|integer|min:1',
            'holiday'=> 'required|integer|min:0',
        ]);


        $roster_holiday = new RosterEmployeeHoliday();
        $roster_holiday->employee_id = $request->emp_id;
        $roster_holiday->holiday = $request->holiday;
        $roster_holiday->save();

        return redirect()->back()->with([
            'message' => 'Holiday Added Successfully'
        ]);


    }

    public function delete_holiday(Request $request){

        $permission = permission(Auth::user()->role_id,170);
        if($permission == false){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }
        $this->validate($request,[
            'holiday_id'=>'required|integer|min:1'
        ]);

        RosterEmployeeHoliday::destroy($request->holiday_id);

        return redirect()->back()->with([
            'message' => 'Holiday Delete Successfully'
        ]);
    }

    public function leave_edit($id){
        $permission = permission(Auth::user()->role_id,170);
        if($permission == false){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }
        $holiday = RosterEmployeeHoliday::findOrfail($id);
        $departments = Department::where('status',1)->get();

        $dept = !empty($holiday->employee->department_name->department) ? $holiday->employee->department_name->id : null;

        $employees = Employee::where('status','active')
            ->where('role_id',6)
            ->where('department',$dept)->get();





        return view('admin.roaster.holiday.edit',compact('departments','holiday','dept','employees'));

    }

    public function leave_update(Request $request,$id){

        $permission = permission(Auth::user()->role_id,170);
        if($permission == false){
            return redirect('permission-error')->with([
                'message' => language_data('You do not have permission to view this page'),
                'message_important'=>true
            ]);
        }
        $this->validate($request,[
            'department_id'=> 'required|integer|min:1',
            'team_id'=> 'nullable|integer|min:0',
            'emp_id'=> 'required|integer|min:1',
            'holiday'=> 'required|integer|min:0',
        ]);


        $roster_holiday = RosterEmployeeHoliday::findOrFail($id);
        $roster_holiday->employee_id = $request->emp_id;
        $roster_holiday->holiday = $request->holiday;
        $roster_holiday->save();

        return redirect()->route('roster.leave')->with([
            'message' => 'Holiday Updated Successfully'
        ]);

    }



    public function roster_copy(Request $request){
        ini_set('max_execution_time', '0'); // for infinite time of execution
        ini_set("memory_limit",-1);
        set_time_limit(0);

        $roster_type  = (int)$request->roster_type;

        if($roster_type ==1){
            $this->validate($request,[
                'project_id'=> 'required|integer|min:1',
                'roster_type' => 'required|integer|min:1',
                'week_start' => 'required|date_format:Y-m-d',
                'week_end' => 'required|date_format:Y-m-d',

            ]);
        }
        else{
            $this->validate($request,[
                'project_id'=> 'required|integer|min:1',
                'month_name' => 'required|date_format:m',
                'year'=> 'required|date_format:Y',
            ]);
        }

        if($roster_type !=1){
            $roster_availability = Roster::where('year',$request->year)
                ->where('project_id',$request->project_id)
                ->where('month',$request->month_name)->first();

            if(!empty($roster_availability)){
                return redirect()->back()->with([
                    "message" => "Roster is already initiated on this {$request->year}-{$request->month_name} date for this project",
                    "message_important" => true
                ]);
            }

        }

        // Checking if shifts exists for project

        $project = Project::findOrfail($request->project_id);

        $shift_lists = array();


        foreach ($project->shift as $shift){
            $shift_lists[] = $shift->project_id;



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
        $roster->roster_type = $roster_type;
        if($roster_type ==1){
            $roster->year = date('Y',strtotime($request->week_start));
            $roster->month = date('m',strtotime($request->week_start));
            $roster->weekly_start = $request->week_start;
            $roster->weekly_end = $request->week_end;
        }
        else{
            $roster->year = $request->year;
            $roster->month = $request->month_name;
        }


        $roster->created_by = Auth::id();

        $roster->save();


        // Getting all days List excluding vacation


        $lists = $this->getting_all_days($roster_type,$roster,$request->all());

        $sorted_list = array();


        $sorted_list = array_values($lists);





        //Copying Roster

        $roster_infos = RosterInfo::where('roster_id',$request->roster_id)->get();



        $previous_roster = array();

        foreach ($roster_infos as $roster_info){
            if(!in_array($roster_info->date_list,$previous_roster)){
                $previous_roster[] = $roster_info->date_list;
            }

            RosterInfo::create([
                'roster_id' =>$roster->id,
                'shift_id' => $roster_info->shift_id,
                'seat_id' => $roster_info->seat_id,
                'agent_id' => $roster_info->agent_id,
                'date_list' => $roster_info->date_list,

            ]);


        }





        $count = 0;

        $roster_infos = RosterInfo::where('roster_id',$roster->id)->get();

        foreach ($roster_infos as $roster_copying){

            if(isset($previous_roster[$count]) && isset($sorted_list[$count])){
                RosterInfo::where('roster_id',$roster->id)
                    ->where('date_list',$previous_roster[$count])->update([
                        'date_list' => $sorted_list[$count]
                    ]);

            }
            else{
                $count =0;
            }

            $count++;





        }





        return redirect()->back()->with([
            "message" => "Roster Copied Successfully",
        ]);




    }

    public function date_wise_seat(Request $request){
        if($request->ajax()){
            $roster_id = $request->rosterId;
            $forceDate = $request->forceDate;

            $roster = Roster::findOrFail($roster_id);
            $project = Project::findOrFail($roster->project_id);


            //Finding available seats
            $roster_info = RosterInfo::where('roster_id',$roster_id)->where('date_list',$forceDate)->get(['seat_id']);

            $busy_seat = array();
            $free_seat = array();

            foreach ($roster_info as $info){
                $busy_seat[] = $info->seat_id;
            }

            $roster_seats = explode(",",$project->seat->seat_id);
            foreach ($roster_seats as $seat){
                if(!in_array($seat,$busy_seat)){
                   $free_seat[] = $seat;
                }
            }
            $roster_seats = Seat::whereIn('id',$free_seat)->get();

            return response()->json($roster_seats);

        }
    }



    public function getting_all_days($roster_type,$roster,$request){
        $request = (object)$request;
        $weekend = lateOvertime::all(['weekend']);

        $weekend_lists = array();

        foreach ($weekend as $week){
            $weekend_lists[] = explode(",",$week->weekend);
        }

        if(!empty($weekend_lists)){
            $arr =  array_flatt($weekend_lists);
            $n = sizeof($arr);
            $k = 8;
            // Got Common Weekend

            $values = array_count_values($arr);
            arsort($values);

            $common_weekend = array_slice(array_keys($values), 0, 5, true);
            if(count($common_weekend) !=0){
                $common_weekend = $common_weekend[0];
            }


            if($common_weekend ==0){$common_weekend = "Sunday";}
            elseif($common_weekend ==1){$common_weekend = "Monday";}
            elseif($common_weekend ==2){$common_weekend = "Tuesday";}
            elseif($common_weekend ==3){$common_weekend = "Wednesday";}
            elseif($common_weekend ==4){$common_weekend = "Thursday";}
            elseif($common_weekend ==5){$common_weekend = "Friday";}
            elseif($common_weekend ==6){$common_weekend = "Saturday";}

        }else{
            $common_weekend = "Friday";
        }

        // Getting 1 Official Off day Start
        $off_day = array();
        $collection_of_off_day = array();

        if($roster_type ==1){
            $date = date($roster->year.'-'.$roster->month);
        }
        else{
            $date = date($request->year.'-'.$request->month_name);
        }


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


        //Finding all dates For Weekly
        if($roster_type ==1){
            $days_length = getDatesFromRange($request->week_start,$request->week_end);
            foreach ($days_length as $length){
                $lists[] = $length;
            }
        }
        //Finding all dates for monthly
        else{
            for($d=1; $d<=31; $d++)
            {
                $time=mktime(12, 0, 0, $request->month_name, $d, $request->year);
                if (date('m', $time)==$request->month_name) {
                    $lists[] = date('Y-m-d', $time);
                }
            }
        }


        // Cutting off days from date
        foreach ($lists as $key=>$list){
            if(in_array($list,$collection_of_off_day)){

                unset($lists[$key]);
            }
        }

        return $lists;

    }


    public function obligation($list,$agent,$shift){
        $obligation = Obligation::where('obligation_date', $list)->where('agent_id', $agent)->first(['id','obligation_date']);
        $obligated_agent =0; //Assigning permission if not obliged


        if (!empty($obligation)) {
            if ($list == $obligation->obligation_date) {
                $obligation_date = $obligation->obligation_date;

                $startTime = $shift->service_start;
                $endTime = $shift->service_end;



                $obligated_agent = Obligation::where('obligation_date', $obligation_date)
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
                    })->count();


                // End of foreach

            }

        }

        return $obligated_agent;
    }

    public function days_of_holiday($list,$agent){
        $day_of_holiday = strtolower(date('l',strtotime($list)));



        if($day_of_holiday == strtolower("Sunday")){$day_of_holiday= 0;}
        elseif($day_of_holiday == strtolower("Monday")){$day_of_holiday =1;}
        elseif($day_of_holiday == strtolower("Tuesday")){$day_of_holiday =2;}
        elseif($day_of_holiday == strtolower("Wednesday")){$day_of_holiday =3;}
        elseif($day_of_holiday == strtolower("Thursday")){$day_of_holiday =4;}
        elseif($day_of_holiday == strtolower("Friday")){$day_of_holiday =5;}
        elseif($day_of_holiday == strtolower("Saturday")){$day_of_holiday =6;}



        $holidays = RosterEmployeeHoliday::where('employee_id',$agent)->where('holiday',$day_of_holiday)->count();

        return $holidays;

    }


}
