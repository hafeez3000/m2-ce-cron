<?php
namespace MageMojo\Cron\Model;

use Magento\Framework\App\ObjectManager;

class Schedule extends \Magento\Framework\Model\AbstractModel 
{
    private $cronconfig;
    private $directorylist;
    private $cronschedule;
    private $resource;
    private $maintenance;
 
    public function __construct(\Magento\Cron\Model\Config $cronconfig,
      \Magento\Framework\App\Filesystem\DirectoryList $directorylist,
      \MageMojo\Cron\Model\ResourceModel\Schedule $resource,
      \Magento\Framework\App\MaintenanceMode $maintenance) {
      $this->cronconfig = $cronconfig;
      $this->directorylist = $directorylist;
      $this->resource = $resource;
      $this->maintenance = $maintenance;
    }


    public function getConfig() {
      $jobs = array();
      foreach($this->cronconfig->getJobs() as $group) {
        foreach($group as $job) {
          $jobs[$job["name"]] = $job;
        }
      }
      $this->config = $jobs;
    }
    
    public function initialize() {
      $this->getConfig();
      $this->getRuntimeParameters();
      $this->cleanupProcesses();
      $this->lastJobTime = $this->resource->getLastJobTime();
      if ($this->lastJobTime < time() - 360) {
        $this->lastJobTime = time();
      }
      $pid = getmypid();
      $this->setPid('cron.pid',$pid);
    }

    public function checkPid($pidfile) {
      if (file_exists($this->basedir.'/var/cron/'.$pidfile)){
        $scheduleid = file_get_contents($this->basedir.'/var/cron/'.$pidfile);
        return $scheduleid;
      }
      return false;
    }
   
    public function setPid($file,$scheduleid) {
      #print 'file='.$file;
      file_put_contents($this->basedir.'/var/cron/'.$file,$scheduleid);
    }

    public function unsetPid($pid) {
      unlink($this->basedir.'/var/cron/'.$pid);
    }
    
    public function getRunningPids() {
      $pids = array();
      $filelist = scandir($this->basedir.'/var/cron/');
      
      foreach ($filelist as $file) {
        if ($file != 'cron.pid') {
          $pid = str_replace('cron.','',$file);
          if (is_numeric($pid)) {
            $pids[$pid] = file_get_contents($this->basedir.'/var/cron/'.$file);
          }
        }
      }
      return $pids;
    }
     
    public function checkProcess($pid) {
      if (file_exists( "/proc/$pid" )){
        return true;
      }
      return false;
    }
    
    public function getJobOutput($scheduleid) {
      $file = $this->basedir.'/var/cron/schedule.'.$scheduleid;
      if (file_exists($file)){
        return trim(file_get_contents($file));
      }
      return NULL; 
    }

    public function cleanupProcesses() {
      $running = array();
      $pids =  $this->getRunningPids();
      foreach ($pids as $pid=>$scheduleid) {
        if (!$this->checkProcess($pid)) {
          $this->unsetPid('cron.'.$pid);
          $this->resource->resetSchedule();
        } else {
          array_push($running,$pid);
        }
      }
      $this->runningPids = $running;
    }
    
    public function getRuntimeParameters() {
      $this->simultaniousJobs = $this->resource->getConfigValue('magemojo/cron/jobs',0,'default');
      $this->phpproc = $this->resource->getConfigValue('magemojo/cron/phpproc',0,'default');
      $this->maxload = $this->resource->getConfigValue('magemojo/cron/maxload',0,'default');
      $this->history = $this->resource->getConfigValue('magemojo/cron/history',0,'default');
      $this->cronenabled = $this->resource->getConfigValue('magemojo/cron/enabled',0,'default');
    }
    
    public function checkCronExpression($expr,$value) {
      foreach (explode(',',$expr) as $e) {
        if (($e == '*') or ($e == $value)) {
          return true;
        }
        $i = explode('/',$e);
        if (count($i) == 2) {
          if (ctype_digit($value / $i[1])) {
            return true;
          }
        }
        $i = explode('-',$e);
        if (count($i) == 2) {
          if (($value > $i[0]) and ($value < $i[1])) {
            return true;
          }
        }
      }
      return false;
    }
   
    public function createSchedule($from, $to) {
      $this->getConfig();
      foreach($this->config as $job) {
        if (isset($job["schedule"])) {
          $schedule = array();
          $expr = explode(' ',$job["schedule"]);
          $buildtime = (round($from/60)*60);
          while ($buildtime <= $to) {
            #print $buildtime;
            $buildtime = $buildtime + 60;
            if (($this->checkCronExpression($expr[4],date('w',$buildtime)))
              and ($this->checkCronExpression($expr[3],date('n',$buildtime)))
              and ($this->checkCronExpression($expr[2],date('j',$buildtime)))
              and ($this->checkCronExpression($expr[1],date('G',$buildtime)))
              and ($this->checkCronExpression($expr[0],(int)date('i',$buildtime)))) {
              array_push($schedule,$buildtime);
            }
          }
          if (count($schedule) > 0) {
            $this->resource->saveSchedule($job,time(),$schedule);
          }
        }
      }
    }
 
    public function execute() {
      $this->basedir = $this->directorylist->getRoot(); 
      print "Healthchecking Cron Service\n";
      $pid = $this->checkPid('cron.pid');
      if (!$this->checkProcess($pid) or (!$pid)) {
        $this->initialize();
        if ($this->cronenabled == 0) {
          exit;
        } else {
          $this->service();
        }
      }
    }

    public function getJobConfig($jobname) {
      return $this->config[$jobname]; 
    }

    public function prepareStub($jobconfig, $stub) {
      $code = trim($stub);
      $code = str_replace('<<basedir>>',$this->basedir,$code);
      $code = str_replace('<<method>>',$jobconfig["method"],$code);
      $code = str_replace('<<instance>>',$jobconfig["instance"],$code);
      return $code;
    }

    public function canRunJobs($jobcount, $pending) {
      $cpunum = exec('cat /proc/cpuinfo | grep processor | wc -l');
      if (!$cpunum) {
        $cpunum = 1;
      }
      if ((sys_getloadavg()[0] / $cpunum) > $this->maxload) {
        print "Crons suspended due to high load average: ".(sys_getloadavg()[0] / $cpunum)."\n";
      }
      $maint = $this->maintenance->isOn();
      if ($maint) {
         print "Crons suspended due to maintenance mode being enabled \n";
      }
      if (($jobcount < $this->simultaniousJobs) 
        and (count($pending) > 0)
        and ((sys_getloadavg()[0] / $cpunum) < $this->maxload)
        and (!$maint)) {
        return true;
      }
      return false;
    }

    public function service() {
      $stub = file_get_contents(__DIR__.'/stub.txt');
      print "Starting Service\n";
      while (true) {
        $this->getRuntimeParameters();
        if ($this->cronenabled == 0) {
          exit;
        }
        #Checking if new jobs need to be scheduled
        if ($this->lastJobTime < time()) {
          print "In schedule loop\n";
          $this->createSchedule($this->lastJobTime, $this->lastJobTime + 3600);
          $this->lastJobTime = $this->resource->getLastJobTime();
        }
        #Checking running jobs
        print "Checking running jobs\n";
        $running = $this->getRunningPids();
        $jobcount = 0;
        foreach ($running as $pid=>$scheduleid) {
          if (!$this->checkProcess($pid)) {
            $output = $this->getJobOutput($scheduleid);
            if (strpos(strtolower($output),'error') > 0) {
              $this->resource->setJobStatus($scheduleid,'error',$output);
            } else {
              $this->resource->setJobStatus($scheduleid,'success',$output);
            }
            $this->unsetPid('cron.'.$pid);
          } else {
            $jobcount++;
          }
        }
        #get pending jobs
        print "Getting pending jobs\n";
        $pending = $this->resource->getPendingJobs();
        while ($this->canRunJobs($jobcount, $pending)) {
          #print "In job run loop\n";
          $job = array_pop($pending);
          $runcheck = $this->resource->getJobByStatus($job["job_code"],'running');
          if (count($runcheck) == 0) {
            $jobconfig = $this->getJobConfig($job["job_code"]);
            $runtime = $this->prepareStub($jobconfig,$stub);
            $cmd = $this->phpproc." -r '".$runtime."' &> ".$this->basedir."/var/cron/schedule.".$job["schedule_id"]." & echo $!";
            $pid = exec($cmd);
            $this->setPid('cron.'.$pid,$job["schedule_id"]);
            $this->resource->setJobStatus($job["schedule_id"],'running',NULL);
            $jobcount++;
            if ($job["job_count"] > 1) {
               print "Setting missed jobs\n";
              $this->resource->setMissedJobs($job["job_code"]);
            }
          }
        }
        #Sanity check processes and look for escaped inmates
        $this->asylum();
        sleep(5);
      }
    }
    
    public function asylum() {
      $crons = $this->getRunningPids();
      $jobs = $this->resource->getJobsByStatus('running');
      $running = array();
      $schedules = array();
      $pids = array();
      foreach ($crons as $pid=>$scheduleid) {
        array_push($running,$scheduleid);
        $pids[$scheduleid] = $pid;
      }
      foreach ($jobs as $job) {
        array_push($schedules,$job["schedule_id"]);
      }
      $diff = array_diff($schedules,$running);
      foreach ($diff as $scheduleid) {
        print "Found mismatched job status for schedule_id ".$scheduleid."\n";
        $this->resource->setJobStatus($scheduleid, 'error', 'Missing PID for process');
      }
      $diff = array_diff($running,$schedules);
      foreach ($diff as $scheduleid) {
        $pid = $pids["schedule_id"];
        print "Found orphaned pid file for schedule_id ".$scheduleid."\n";
        $this->unsetPid('cron.'.$pid);
      }
    }
    
    public function getScheduleOutputIds() {
      $filelist = scandir($this->basedir.'/var/cron/');
      $scheduleids = array();
      foreach ($filelist as $file) {
        if (strpos($file,'schedule.') === true) {
          array_push($scheduleids,explode('.',$file)[1]);
        }
      }
      return $scheduleids;
    }

    public function cleanup() {
      $this->basedir = $this->directorylist->getRoot();
      $this->initialize();
      $scheduleids = $this->resource->cleanSchedule($this->history);
      $fileids = $this->getScheduleOutputIds();
      $diff = array_diff($fileids,$scheduleids);
      foreach ($diff as $id) {
        $this->unsetPid('schedule.'.$id);
      }
    }
}

