<?php
    class SharkDbManager {
        /*
        Public Variables
			$status: Possible values: "OK" and "ERROR"
			$message: Error or Warning messages
        */
        public $status; 
        public $message;

        /*
        Private Variables
			$engines: List of possible database engine
			$engine: Selected engine
			$resource: Connection with database
			$users: Starter array of users
			$servers: Starter array of servers
        */
        private $engines = Array("MySQL" => "mysql", "PostgreSQL" => "pg", "MS_SQL" => "mssql");
		private $users = Array();
		private $servers = Array();
        private $engine;
        private $resource;		
		
        
        public function setEngine($engineName){
            $this->clearStatusMessage();
            $this->engine = null;
            foreach($this->engines as $name=>$val){
                if(strtolower(trim($engineName)) == strtolower(trim($name))){
                    if(function_exists($val.'_connect')){
                        $this->engine = $val;
                    }
                }
            }
            if($this->engine == null){
                $this->status = "ERROR";
                $this->message = "The \"$engineName\" engine is not available in this server.\n";
                return false;
            } else {
                $this->status = "OK";
                return true;
            }
        }

        public function getEngine(){
            $this->clearStatusMessage();
            if($this->engine == null){
                return  "Engine not configured.\n";
            } else {
                return array_search($this->engine,$this->engines);
            }
        }

        public function listEngines(){
            $this->clearStatusMessage();
            foreach($this->engines as $name=>$val){
                echo function_exists($val.'_connect') ? $name.": Available"."\n": $name.": Not available in this server"."\n";
            }
        }
		
        public function loadConfig($dbUser,$server){
			$pathList = explode("\\",__FILE__);
			$xml = simplexml_load_string(file_get_contents(str_replace($pathList[(count($pathList)-1)],"",__FILE__)."config.xml"));
			
			foreach($xml->users->user as $val){
				$this->users[(string)$val->name] = (string)$val->password;
			}
			
			foreach($xml->servers->server as $val){
				$this->servers[((string)$val->attributes()->name!="" ? (string)$val->attributes()->name : count($this->servers) )] = array( "engine" => (string)$val->engine, "server" => (string)$val->server, "port" => (string)$val->port,"database" => (string)$val->database);
			}
			
            $this->clearStatusMessage();
            if(!$this->checkUser($dbUser)){$this->status="ERROR"; return $this->message= "The user \"".$dbUser."\" is not configured."; }
            if(is_bool($configEngine = $this->getConfigEngine($server))){ $this->status = "ERROR"; return $this->message = "The server \"".$server."\" is not configured."; }
            $this->setEngine($configEngine);
            if($this->engine == null) { return false;}
            $this->Connect($this->connectionConfig($dbUser,$server));
            if($this->status == "ERROR") { return false;}
        }

        public function Connect($config){
            $this->clearStatusMessage();
            $this->resource = null;
            if($this->engine){
                if(is_array($config)){
                    switch($this->engine){
                        case "mysql":
                            $this->resource = @mysql_connect($config['server'].(isset($config['port'])?":".$config['port']:""),$config['username'],$config['password']);
                            if($this->resource){
                                $this->status = (@mysql_select_db($config['database'],$this->resource)?"OK":"ERROR");
                                $this->message = ($this->status == "ERROR" ? @mysql_error() : "");
                                if($this->status == "ERROR"){ return false; } else { return true; }
                            } else {
                                $this->status = "ERROR";
                                $this->message = ($this->status == "ERROR" ? @mysql_error() : "");
                                return false;
                            }
                            break;
                        case "mssql":
                            $this->resource = @mssql_connect($config['server'].(isset($config['port'])?":".$config['port']:""),$config['username'],$config['password']);
                            if($this->resource){
                                $this->status = (@mssql_select_db($config['database'],$this->resource)?"OK":"ERROR");
                                $this->message = ($this->status == "ERROR" ? @mssql_get_last_message() : "");
                                if($this->status == "ERROR"){ return false; } else { return true; }
                            } else {
                                $this->status = "ERROR";
                                $this->message = ($this->status == "ERROR" ? @mssql_get_last_message() : "");
                                $this->message = ($this->message == "" ? "Could not connect on ".$config['server'].(isset($config['port'])?":".$config['port']:"")."." : $this->message );
                                return false;
                            }
                        break;
                        case "pg":
                            $this->resource = @pg_connect("host=".$config['server'].(isset($config['port'])?" port=".$config['port']:"")." dbname=".$config['database']." user=".$config['username']." password=".$config['password']);
                            if(!$this->resource){
                                $this->status = "ERROR";
                                $this->message = ($this->status == "ERROR" ? (is_bool($this->resource)? "Could not connect on ".$config['server'].(isset($config['port'])?":".$config['port']:"")."." : @pg_last_error($this->resource)) : "");
                                return false;
                            }
                        break;
                    }
                } else {
                    $this->status = "ERROR";
                    $this->message =   "Invalid configuration.\n";
                    return false;
                }
            } else {
                $this->status = "ERROR";
                $this->message =   "Engine not configured.\n";
                return false;
            }
        }

        public function Query($query){
            $this->clearStatusMessage();
            if($this->resource != null){
                $result = ($this->engine != "pg" ? call_user_func($this->engine."_query",$query,$this->resource) : call_user_func($this->engine."_query",$this->resource,$query));
                if (!$result) { $this->handlingErrors(); return false; }
                $this->status = "OK";
                if(is_bool($result)){ return true; }
                if(($rows = call_user_func($this->engine."_num_rows",$result)) == 0){
                    $this->message = "No rows fetched.";
                    return $rows;
                } else {
                    $i = 0;
                    while($row = call_user_func($this->engine."_fetch_assoc",$result)){
                        $returnValue[$i] = $row;
                        $i++;
                    }
                    return $returnValue;
                }
            } else {
                $this->status = "ERROR";
                $this->message = "Not connected.";
            }
        }
        
        private function handlingErrors(){
            $this->status = "ERROR";
            switch($this->engine){
                case "mysql":
                    $this->message = @mysql_error($this->resource);
                break;
                case "mssql":
                    $this->message = @mssql_get_last_message();
                break;
                case "pg":
                    $this->message = @pg_last_error($this->resource);
                break;
            }
        }

        private function clearStatusMessage(){
            $this->status = "";
            $this->message = "";
        }
		
        public function getConfigEngine($server){
            if(isset($this->servers[$server])){
                return $this->servers[$server]["engine"];
            } else {
                return false;
            }
        }
        
        public function checkUser($user){
            if(isset($this->users[$user])){
                return true;
            } else {
                return false;
            }
        }
        
        public function connectionConfig($user,$server){
            if(isset($this->servers[$server])){
                if($this->servers[$server]["port"] != "" ){
                    return Array("server"=>$this->servers[$server]["server"], "port" => $this->servers[$server]["port"], "database"=>$this->servers[$server]["database"], "username"=>$user, "password"=>$this->users[$user]);
                }
                return Array("server"=>$this->servers[$server]["server"], "database"=>$this->servers[$server]["database"], "username"=>$user, "password"=>$this->users[$user]);
            } else {
                die ("The server \"".$server."\" is not configurated.");
            }
        }
    }
?>