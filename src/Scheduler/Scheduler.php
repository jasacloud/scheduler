<?PHP
	class Scheduler extends System {
		
		var $system;
		var $db = NULL;
		public function __construct($data_source=NULL){
			if($data_source!=NULL){
				$this->data_source = $data_source;
			}
			$this->system = new System();
		}
		
		public function getDateSlot($interval,$datelimit,$excludedate=[],$excludeweek=[]){
			$dateresult = [];
			$index = 0;
			$datetime = new DateTime($datelimit['min']);
			
			for($dateresult[] = $datetime->format('Y-m-d'); date("Y-m-d", strtotime("+".$interval." day", strtotime($dateresult[$index])))<=$datelimit['max'];$index++){
				$datetime->modify($interval . ' days');

				$dateresult[] = $datetime->format('Y-m-d');
				
				if($index>1000){
					break;
				}
			}
			
			$result_temp = array_diff($dateresult, $excludedate);
			
			foreach($result_temp as $val){
				if(!in_array(date("w", strtotime($val)),$excludeweek)){
					$result[] = $val;
				}
			}
			
			return array_values($result);
			//echo json_encode(array_values($result));
			//die;
		}
		
		public function getTimeSlot($interval,$timelimit,$excludetime=[]){
			$timeresult = [];
			$index = 0;
			$datetime = new DateTime('2000-01-01');
			$datetime->modify($this->system->getTime2Sec($timelimit['min']) . ' seconds');
			if($this->system->getTime2Sec($timelimit['min'])==$this->system->getTime2Sec($timelimit['max'])){
				$timeresult = [];
			}
			else{
				for($timeresult[] = $datetime->format('H:i'); ($this->system->getTime2Sec($timeresult[$index])+$this->system->getTime2Sec($interval))<$this->system->getTime2Sec($timelimit['max']);$index++){
					$datetime->modify($this->system->getTime2Sec($interval) . ' seconds');
					$timeresult[] = $datetime->format('H:i');
					if($index>1000){
						break;
					}
				}
			}
			$result = array_diff($timeresult, $excludetime);
			return array_values($result);
			//print_r($result);
			//echo json_encode(array_values($result));
			//die;
		}
		
		public function test(){
		
			$array = [
				"2017-1"=>["1","2","3"],
				"2017-2"=>["1","2","3"],
				"2017-3"=>["1","2","3"]
			];
			
			unset($array["2017-2"]);
			var_dump($array);die;
			
			var_dump(array_diff([1,2,3],[1,2]));die;
			
			echo date('w', time("2017-01-03"));die;
			echo date('Y-m-d', strtotime("+7 day", time()));
			die;
			//$this->getTimeSlot("00:25",["min"=>"08:00","max"=>"23:00"],["18:00"]);
			$this->getDateSlot("1",["min"=>"2017-02-01","max"=>"2017-02-07"],["2017-02-03"],[0]);
			die;
		}
		
		public function add(){
			if(!$this->db){
				$this->db = new DBManager('scheduler');
			}
			
			switch($this->data_source->properties->type){
				case 'curl':
					$data = [
						"FID"=>$this->data_source->properties->id,
						"FType"=>"curl",
						"FExecuteOn"=>$this->data_source->properties->on,
						"FEnabled"=>"1",
						"FJobs"=>json_encode($this->data_source->properties),
						"FLastUpdate"=>parent::getDateTime2(),
						"FCreateOn"=>parent::getDateTime2(),
						"FRunOnce"=>isset($this->data_source->properties->runonce) ? $this->data_source->properties->runonce : true
					];
					
					$crontab = new CrontabManager();
					$job = $crontab->newJob();
					//curl -H "Content-Type: application/json" -X POST -d '{"username":"xyz","password":"xyz"}' http://localhost:3000/api/login
					$cmdjob = "curl -H 'Content-Type: application/json' -X POST -d ";
					$cmdjob .=  '\'{"kind":"run#scheduler","properties":{"id":"'. $this->data_source->properties->id .'"}}\' ';
					$cmdjob .= HTTP_SERVER['schedulerrunner']['protocol']."://".HTTP_SERVER['schedulerrunner']['host'].":".HTTP_SERVER['schedulerrunner']['port']."/".HTTP_SERVER['schedulerrunner']['uri'];
					
					if(CRONTAB_CONFIG['type']=='database'){
						$job->onDateTime($this->data_source->properties->on)->id($this->data_source->properties->id)->doJob($cmdjob);
						//$crontab->add($job);
						//$crontab->save();
					}
					else{
						$job->onDateTime($this->data_source->properties->on)->id($this->data_source->properties->id)->doJob($cmdjob);
						$crontab->add($job);
					}
					$result = $this->db->insert('scheduler',$data);
					if($result){
						$savecrontab = CRONTAB_CONFIG['type']=='database' ? $crontab->insertJob($job) : $crontab->save();
						
						return [
							"returnval"=>true,
							"returnmsg"=>"Success add scheduler on DB" . ($savecrontab ? ' and cron.' : ', But failed to add cron!') ,
							"properties"=>[
								"id"=>$this->data_source->properties->id
							]
						];
					}
					else{
						return [
							"returnval"=>false,
							"returnmsg"=>"Failed add scheduler (duplicate or format failed)",
							"properties"=>[
								"id"=>$this->data_source->properties->id
							]
						];
					}
				break;
				
				default:
					return ['returnval'=>false,'returnmsg'=>'Scheduler type not available!'];
				break;
			}
		}
		
		public function delete(){
			if(!$this->db){
				$this->db = new DBManager('scheduler');
			}
			if($this->data_source->properties->id){
				$crontab = new CrontabManager();
				
				if(CRONTAB_CONFIG['type']=='database'){
					$job = $crontab->deleteDBJob($this->data_source->properties->id);
					$saveresult = 1;
				}
				else{
					$job = $crontab->deleteJob($this->data_source->properties->id);
					$saveresult = $crontab->save(false);
				}
				
				$result = $this->db->deleteRow("scheduler", ["FID"=>$this->data_source->properties->id]);
				if($saveresult && $result){
					return [
						"returnval"=>true,
						"returnmsg"=>"Success delete scheduler, total $job cron deleted and $result row DB deleted!",
						"properties"=>[
							"id"=>$this->data_source->properties->id
						]
					];
				}
				else if($saveresult && !$result){
					return [
						"returnval"=>true,
						"returnmsg"=>"Success delete scheduler, total $job cron deleted, but failed while deleting on DB or nothing row to deleted!",
						"properties"=>[
							"id"=>$this->data_source->properties->id
						]
					];
				}
				else if(!$saveresult && $result){
					return [
						"returnval"=>true,
						"returnmsg"=>"Success delete scheduler on DB total $result row deleted, but error while deleteing on cron!",
						"properties"=>[
							"id"=>$this->data_source->properties->id
						]
					];
				}
				else{
					return [
						"returnval"=>false,
						"returnmsg"=>"Failed delete scheduler on both cron and DB",
						"properties"=>[
							"id"=>$this->data_source->properties->id
						]
					];
				}
				
			}
		}
		
		public function run(){
			if(!$this->db){
				$this->db = new DBManager('scheduler');
			}
			if($this->data_source->properties->id){
				$crontab = new CrontabManager();
				
				$data = $this->db->getAllRow("scheduler",["FID"=>$this->data_source->properties->id]);
				if($data){
					$row = $data[0];
					switch($row['FType']){
						case "curl":
							$FJobs = json_decode($row['FJobs']);
						
							$curl = new Curl();
							$curl->setHeader("Content-Type","application/json");
							$curl->post($FJobs->host, (array)$FJobs->content);
							
							$response = (array)$curl->response;
							
							if($row['FRunOnce']){
								$this->delete();
							}
							else{
								$this->db->update('scheduler',["FExecuted"=>true,"FLastExecuted"=>parent::getDateTime2()],["FID"=>$this->data_source->properties->id]);
							}
							
							return [
								"returnval"=>true,
								"returnmsg"=>"Succes to execute scheduler!"
							];
							
						break;
						default:
						
							return [
								"returnval"=>false,
								"returnmsg"=>"Scehdule type not available!"
							];
						break;
					}
					
				}
				else{
					$this->delete();
					return [
						"returnval"=>false,
						"returnmsg"=>"Scheduler ID not found in DB!"
					];
				}
				
			}
			else{
				return [
					"returnval"=>false,
					"returnmsg"=>"Please pass valid parameters for id!"
				];
			}
		}
		
	}
?>