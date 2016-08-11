<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use DB;
use PDO;

use App\Lead;
use App\Project;
use App\Manager;


/**
* Class DashboardController
* @package App\Http\Controllers\Backend
*/
class StatisticController extends Controller
{
    /**
    * @return \Illuminate\View\View
    */
    public function index()
    {
        return view('backend.dashboard');
    }

        /**
    * @return \Illuminate\View\View
    */
    public function managers()
    {
        return view('backend.statistic.managers');
    }

        /**
    * @return \Illuminate\View\View
    */
    public function leads()
    {
        return view('backend.statistic.leads');
    }

    /**
    * @return \Illuminate\Http\Response
    */
    public function getLeads(Request $request)
    {
        DB::connection()->setFetchMode(PDO::FETCH_NUM);
        $data = Lead::select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'))->whereNotNull('manager_id')->groupBy('date')->get();
        $data = array_merge([[1451606400000, 0]], $data->toArray());
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }

    /**
    * @return \Illuminate\Http\Response
    */
    public function getActive(Request $request)
    {
        DB::connection()->setFetchMode(PDO::FETCH_NUM);
        $query = Lead::select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'));
        $query->whereNotNull('manager_id');
        $query->where('password_default', 1);
        $query->groupBy('date');
        $data = $query->get();
        $data = array_merge([[1451606400000, 0]], $data->toArray());
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }

    /**
    * @return \Illuminate\Http\Response
    */
    public function getUse(Request $request)
    {
        DB::connection()->setFetchMode(PDO::FETCH_NUM);
        $leads = Lead::whereNotNull('manager_id')->get()->lists(0)->toArray();
        $query = Project::whereIn('user_id', $leads)->select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'));
        $query->where('correct_code', 1);
        $query->groupBy('date');
        $data = $query->get();
        $data = array_merge([[1451606400000, 0]], $data->toArray());
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }

    /**
    * @return \Illuminate\Http\Response
    */
    public function getLeadsGrowth(Request $request)
    {
        $data = DB::transaction(function()
            {
                DB::connection()->setFetchMode(PDO::FETCH_NUM);
                $sub = Lead::select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'))->whereNotNull('manager_id')->groupBy('date');
                DB::statement(DB::raw('SET @count = 0'));
                return DB::table( DB::raw("({$sub->toSql()}) as sub"))->select(DB::raw('date, @count := @count + total as count'))->get();
        });
        $data = array_merge([[1451606400000, 0]], $data);
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }
    public function getActiveGrowth(Request $request)
    {
        $data = DB::transaction(function()
            {
                DB::connection()->setFetchMode(PDO::FETCH_NUM);
                $sub = Lead::select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'));
                $sub->whereNotNull('manager_id');
                $sub->whereRaw('password_default = 1');
                $sub->groupBy('date');
                DB::statement(DB::raw('SET @count = 0'));
                return DB::table( DB::raw("({$sub->toSql()}) as sub"))->select(DB::raw('date, @count := @count + total as count'))->get();
        });
        $data = array_merge([[1451606400000, 0]], $data);
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }

    public function getUseGrowth(Request $request)
    {
        $leads = Lead::whereNotNull('manager_id')->get()->lists('id')->toArray();
        $sub = Project::select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'))->whereIn('user_id', $leads)->where('correct_code', 1)->where('enabled', 1)->groupBy('date')->groupBy('user_id');
        $sub = app()->getSql($sub);
        DB::connection()->setFetchMode(PDO::FETCH_NUM);
        $query = DB::table( DB::raw("($sub) AS sub, (SELECT @count := 0) AS cnt"))->select(DB::raw('date, @count := @count + total as count'))->get();
        $data = array_merge([[1451606400000, 0]], $query);
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }

    public function getByManagersLeads(Request $request)
    {
        $managers = Manager::whereHas('roles', function($q){
            $q->where('role_id', 3);
        })->get();
        $managers = $managers->each(function ($item, $key) {
            $statistic = array();
            $statistic['id'] = $item->id;
            $statistic['name'] = $item->name;
            $statistic['email'] = $item->email;
            $statistic['sip'] = $item->sip;
            $statistic['registered'] = $item->leadsRegistered()->count();
            $statistic['confirm'] = $item->leadsConfirm()->count();
            $statistic['active'] = $item->leadsActive()->count();
            $statistic['use'] = $item->leadsUsingProjects()->get()->count();
            $statistic['paid'] = $item->leadsPaid()->get()->count();
            return $item->statistic = $statistic;
        });
       /*
        $leads = Lead::whereNotNull('manager_id')->get()->lists('id')->toArray();
        $sub = Project::select(DB::raw('(UNIX_TIMESTAMP(DATE(created_at)) + 10800) * 1000 AS date'), DB::raw('count(*) as total'))->whereIn('user_id', $leads)->where('correct_code', 1)->where('enabled', 1)->groupBy('date')->groupBy('user_id');
        $sub = app()->getSql($sub);
        DB::connection()->setFetchMode(PDO::FETCH_NUM);
        $query = DB::table( DB::raw("($sub) AS sub, (SELECT @count := 0) AS cnt"))->select(DB::raw('date, @count := @count + total as count'))->get();
        $data = array_merge([[1451606400000, 0]], $query);
        $data = $request->callback."(".strtr(json_encode($data), ["\""=>""]).");";
        return response("ok", 200)->header('Content-Type', "text/javascript");
        */
        $data = $request->callback."(".$managers->lists('statistic')->toJson().");";
        return response($data, 200)->header('Content-Type', "text/javascript");
    }
}
