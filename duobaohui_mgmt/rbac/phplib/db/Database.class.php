<?php
namespace Phplib\DB;

class Database {

	const MASTER  = 0;
	const SLAVE   = 1;

	protected	$table;
	protected	$select;  // object
	protected	$prepareType;

	public	$debug;
	public	$dump;

	private $config; 
	private $SQLMonitorClose = 0; 
	private $in_transaction;
	private $last_sth; //@TODO: sort of an ugly hack for retrieving affected_rows
	protected $db_error_log; 
	private $db_conf = array(); //the selected database conf

	/**
	 * Store PDO connections for reusing. This is an array holds different
	 * types (MASTER or SLAVE) of connections.
	 *
	 * @var array
	 */
	private $connection = array();

	/**
	 * Private constructor. Load database config.
	 */
	private function __construct($database) {
		date_default_timezone_set('PRC');
		$this->config = \Phplib\Config::load('MySQL')->$database;
		if(!isset($this->config)){
			echo "MySQL Config {$database} is not set yet." ;die;
			throw new \Exception("Config namespace is not set yet.");
		}
		$this->in_transaction = FALSE;
		$this->last_sth = NULL;
		$this->db_error_log = new \Phplib\Tools\Liblog('db_error', 'normal');
		$this->prepareType = self::SLAVE;
		$this->select = new \stdClass;
	}
	
	public function dump(){
		$this->dump = 1;
	}
	public function debug(){
		$this->debug = 1;
	}

	/**
	 * Singleton.
	 */
	public static function getConn($database=null) {
		static $singletons = array();
		!isset($singletons[$database]) && $singletons[$database] = new Database($database);
		return $singletons[$database];
	}

	/**
	 * Write data into database.
	 *
	 * @param string $sql
	 * @param array $params
	 * @return unknown_type
	 */
	public function write($sql, $params = array()) {
		$rs = FALSE;
		$sth = $this->prepare($sql, $params, self::MASTER);
		$this->last_sth = $sth;
		if($this->catchError($sth, $sql, $params)){
			 $rs = $this->getAffectedRows();
		}
		$sth->closeCursor();
		return $rs;
	}

	/**
	 * Read data from database. 
	 *
	 * @param string $sql SQL query statement
	 * @param array $params bind variable
	 * @param bool $from_master TRUE to query from master and FALSE to query from slave
	 * @param string $hash_key key the result by the specified field
	 */
	public function read($sql, $params = array(), $from_master = FALSE, $hash_key = NULL, $isObj=FALSE) {
		$type = $from_master ? self::MASTER : self::SLAVE;
		$sth = $this->prepare($sql, $params, $type);
		//if( $this->catchError($sth, $sql, $params)){
			$result = array();
			$type = $isObj ?  PDO::FETCH_OBJ : PDO::FETCH_ASSOC;
			while ($row = $sth->fetch($type)) {
				if (isset($hash_key) && !empty($hash_key)) {
					$result[$row[$hash_key]]= $row;
				}
				else {
					$result[]= $row;
				}
			}
			$sth->closeCursor();

			return $result;
		//}
		//return FALSE;
	}

	/**
	 * Get number of affected rows in previous MySQL operation.
	 */
	public function getAffectedRows() {
		return $this->last_sth->rowCount();
	}

	public function getInsertId() {
		$connection = $this->getConnection(self::MASTER);
		return $connection->lastInsertId();
	}


	/**
	 * Get a connection for writing data.
	 *
	 * @param int $type Database::MASTER or Database::SLAVE
	 */
	private function getConnection($type) {
		if (isset($this->connection[$type])) {
			return $this->connection[$type];
		}

		switch ($type) {
			case self::MASTER:
				$conf = $this->config['MASTER'];
				$this->db_conf[self::MASTER] = $conf;
				break;

			case self::SLAVE:
			default:
				$ran = array_rand($this->config['SLAVES']);
				$conf = $this->config['SLAVES'][$ran];
				$this->db_conf[self::SLAVE] = $conf;
				break;
		}
		
		try {
			$this->connection[$type] = new PDO($conf['HOST'], $conf['DB'], $conf['USER'], $conf['PASS'], $conf['PORT']);
			$this->connection[$type]->exec("SET NAMES utf8");
		}
		catch (\PDOException $e) {
			echo "请检查数据库账号信息是否正确！";die;
			$error = 'first:' . $type . json_encode($conf) . $e->getMessage();
			$this->db_error_log->w_log($error);
			if ($type == self::MASTER) {
				return FALSE;
			}

			$count = count($this->config['SLAVES']);
			$start = rand(0, $count - 1);
			for($i = $start; $i < $count + $start; $i++) {
				$slave = ($i >= $count) ? ($i-$count) : $i;
				$slaveConf = $this->config['SLAVES'][$slave];
				if ($slaveConf['HOST'] == $conf['HOST'] && $slaveConf['PORT'] == $conf['PORT']) {
					continue;
				}
				$this->db_conf[self::SLAVE] = $slaveConf;
				try {
					$this->connection[$type] = new PDO($slaveConf['HOST'], $slaveConf['DB'], $slaveConf['USER'], $slaveConf['PASS'], $slaveConf['PORT']);
					$this->connection[$type]->exec("SET NAMES utf8");
				}
				catch (\PDOException $e) {
					$error = 'foreach:' . $type . json_encode($conf) . $e->getMessage();
					$this->db_error_log->w_log($error);
					continue;
				}
				break;
			}
		}

		return isset($this->connection[$type]) ?  $this->connection[$type] : FALSE;
	}

	/**
	 * Prepares a statement for execution and returns a statement object.
	 *
	 * @param string $sql sql statement
	 * @param array $params parameters used in sql statement
	 * @param int $op database operation type
	 */
	private function prepare($sql, $params, $type) {
		$runTimeStart = microtime(true);
		$connection = $this->getConnection($type);
		//if($this->dump || $this->debug){
			$sql_monitor = SQLMonitor::getMonitor();
			$sql_monitor->start($sql, $params);
		//}

		//$sth = $connection->prepare($sql, array(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => FALSE));
		$sth = $connection->prepare($sql);
		$exeParams = array();
		if (!empty($params)) {
			foreach ($params AS $key => $value) {
				$exeParams[ ':'.$key ] = $value;
				$sth->bindValue(":{$key}",
						$value,
					   	PDO::PARAM_STR);
			
				//if (strpos($key, '_') === 0) {
				//	$sth->bindValue(":{$key}", $value, PDO::PARAM_INT);
				//}
				//else {
				//	$sth->bindValue(":{$key}", $value, PDO::PARAM_STR);
				//}
			}
		}
		switch ($type) {
			case self::MASTER:
				$conf = $this->db_conf[self::MASTER];
				break;

			case self::SLAVE:
			default:
				$conf = $this->db_conf[self::SLAVE];
				break;
		}
		
		$sth->execute($exeParams);
		//
		//if($this->dump || $this->debug){
			$sql_monitor->finish($sth, $conf);
			$dump = $sql_monitor->dump();
			$rowDump = end($dump);
			$GLOBALS['sql_dump'] = $rowDump['sql_dump'];
		//}
		// dump
		if($this->dump){
			$sql_dump = '';
			foreach($dump as $v){
				$sql_dump .= '<p>'.$v['sql_dump'].'</p>';
			}
			$html = '<div id="sql_errors_panel" style="padding:20px;background-color:#ccc; position:fixed;  bottom:0;  height:200px; width:100%">';
			$html .= '<div></div><div><h3>sql dump</h3>';
			$html .= $sql_dump . '</div></div>';
			echo $html;
		
		}
		// error
		if($this->debug){
			$error_message = '';
			foreach($dump as $v){
				if($v['error_message']!==null){
					$error_message .= '<p>'.$v['sql_dump'].'</p>' . "\n";
					$error_message .= '<p>'.$v['sql'].'</p>' . "\n";
					$error_message .= '<p>'.$v['params'].'</p>' . "\n";
					$error_message .= '<p>'.$v['error_message'].'</p><hr/>' . "\n";
				}
			}
			if($error_message!=''){
				$html = '<div id="sql_errors_panel" style="padding:20px;background-color:#ccc; position:fixed;  bottom:0;  height:200px; width:100%">';
				$html .= '<div></div><div><h3>sql error</h3>' . "\n";
				$html .= $error_message . '</div></div>' . "\n";
				echo defined('DEBUG') && DEBUG==1  &&  isset($_GET['debug']) && $_GET['debug']=='json' ? strip_tags($html)  : $html;
			}
		}

		$runTimeStop = microtime(true);
		$totalRunTime = number_format($runTimeStop - $runTimeStart, 5);
		$sqlInfo = $sql;
		foreach($exeParams as $k=>$v){
			if(strpos($sqlInfo, $k.',') == false){
				$sqlInfo = str_replace($k, '"'.$v.'"', $sqlInfo);	
			}else{
				$sqlInfo = str_replace($k.',', '"'.$v.'",', $sqlInfo);
			}
		}

		if(defined('APP_DEBUG') && APP_DEBUG==true){
			$_SERVER['sqlinfo'][] = sprintf('[%s] SQL: %s [RunTime: %s]', date('Y-m-d H:i:s', time()), $sqlInfo, $totalRunTime);
		}	
		return $sth;
	}

	/**
	 * Catch database error.
	 * http://www.php.net/manual/en/pdostatement.errorinfo.php
	 *
	 * @param PDOStatement $sth
	 */
	private function catchError($sth, $sql = '', $params = '') {
		list($sql_state, $error_code, $error_message) = $sth->errorInfo();
		if ($sql_state == '00000') {
			return TRUE;
		}

		// rollback if in a transaction
		return $this->rollback();
	}

	private function rollback() {
		if ($this->in_transaction) {
			$connection = $this->getConnection(self::MASTER);
			$connection->rollback();
			$this->in_transaction = FALSE;
		}
		return $this->in_transaction;
	}

	public function isMaster(){
		$this->prepareType = self::MASTER;
	}

	public function fetch($sql=null, $params = array()) {
		if($sql==null){
			$sql	=  $this->createSelectSql();
			strpos($sql, 'LIMIT') || $sql .= ' LIMIT 1';
			$params =  $this->select_param;
		}else{
			$this->where($sql, $params);
			$params = isset($this->select->param)? $this->select->param : array() ;
			$sql	= $this->select->where;
			$this->select = new \stdClass();
		}
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$row = $sth->fetch(PDO::FETCH_OBJ);
			$sth->closeCursor();
			return $row;
		}else{
			return 'fetch error:' . $sql;
		}
	}

	/*
	 * 数组方式
	 */
	public function fetchArr($sql=null, $params = array()) {
		if($sql==null){
			$sql	=  $this->createSelectSql();
			strpos($sql, 'LIMIT') || $sql .= ' LIMIT 1';
			$params =  $this->select_param;
		}else{
			$this->where($sql, $params);
			$params = isset($this->select->param)? $this->select->param : array() ;
			$sql	= $this->select->where;
			$this->select = new \stdClass();
		}
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$row = $sth->fetch(PDO::FETCH_ASSOC);
			$sth->closeCursor();
			return $row;
		}else{
			return 'fetch error:' . $sql;
		}
	}

	/*
	 * 数组方式
	 */
	public function fetchArrAll($sql=null, $params = array()) {
		if($sql==null){
			$sql	= $this->createSelectSql();
			$params = $this->select_param;
		}else{
			$this->where($sql, $params);
			$params = isset($this->select->param)? $this->select->param : array() ;
			$sql	= $this->select->where;
			$this->select = new \stdClass();
		}
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$list = $sth->fetchAll(PDO::FETCH_ASSOC);
			$sth->closeCursor();
			return $list;
		}else{
			return FALSE;
		}
	}

	/*
	 * 对象方式
	 */
	public function fetchAll($sql=null, $params = array()) {
		if($sql==null){
			$sql	= $this->createSelectSql();
			$params = $this->select_param;
		}else{
			$this->where($sql, $params);
			$params = isset($this->select->param) ? $this->select->param : array();
			$sql	= $this->select->where;
			$this->select = new \stdClass();
		}
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$list = $sth->fetchAll(PDO::FETCH_OBJ);
			$sth->closeCursor();
			return $list;
		}else{
			return FALSE;
		}
	}


	/*
	 *按第一列为键名 
	 */
	public function fetchAssocAll($sql=null, $params = array()) {
		if($sql==null){
			$sql	= $this->createSelectSql();
			$params = $this->select_param;
		}else{
			$this->where($sql, $params);
			$params = isset($this->select->param)? $this->select->param : array() ;
			$sql	= $this->select->where;
			$this->select = new \stdClass();
		}
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$list = $sth->fetchAll(PDO::FETCH_OBJ|PDO::FETCH_UNIQUE);
			$sth->closeCursor();
			return $list;
		}else{
			return 'fetchAll error:' . $sql;
		}
	}

	/*
	 * 返回第一列
	 */
	public function fetchCol($sql=null, $params=array()){
		if($sql==null){
			$sql	= $this->createSelectSql();
			$params = $this->select_param;
		}else{
			$this->where($sql, $params);
			$params = isset($this->select->param)? $this->select->param : array() ;
			$sql	= $this->select->where;
			$this->select = new \stdClass();
		}
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$list = $sth->fetchAll(PDO::FETCH_COLUMN);
			$sth->closeCursor();
			return $list;
		}else{
			return 'fetchAll error:' . $sql;
		}
	}


	public function fetchCount(){
		$this->select->field = ' COUNT(*) ';
		$sql	= $this->createSelectSql();
		$params = $this->select_param;
		$sth = $this->prepare($sql, $params, $this->prepareType);
		if( $this->catchError($sth, $sql, $params) ){
			$num = $sth->fetchColumn();
			$sth->closeCursor();
			return (int)$num;
		}else{
			return 'fetchAll error:' . $sql;
		}
	}

	public function delete($where, $param, $table=null){
		is_null($table) && $table = $this->table ;
		if(empty($param)){return FALSE;}

		$where =  ' WHERE ' . $where ;

		foreach($param as $kp=>$vp){
			if( is_array($vp) ){  // in
				$arrInWhere = array();
				foreach($vp as $kd=>$vd){
					$ki = $kp . '_' . $kd;
					$arrInWhere[] = ':'. $ki;
					$arrParams[$ki] = $vd;
				}
				$where = str_replace(':'.$kp, implode(',', $arrInWhere), $where);
			}else{
				$arrParams[$kp] = $vp;
			}
		}
		$sql = 'DELETE FROM '. $table. ' ' . $where;
		return $this->Write($sql,$arrParams);
	}

	public function insert( $data, $table=null){
		is_null($table) && $table = $this->table ;
		$arrData    = is_object($data) ? (array)$data : $data;
		$fields		= array_keys($arrData);
		$strFields	= implode(',',  $fields);
		$strValues	= ':'.implode(',:', $fields);
		$sql = "INSERT INTO {$table}({$strFields}) VALUES({$strValues})";
		$isW = $this->Write($sql,$arrData);
		$lastInsertId = $this->getInsertId();
		return $isW!==FALSE ? ($lastInsertId ? $lastInsertId : true ) : FALSE;
	}

	public function insertIgnore( $arrData, $table=null){
		//$arrData    = is_object($data) ? (array)$data : $data;
		is_null($table) && $table = $this->table ;
		$fields		= array_keys($arrData);
		$strFields	= implode(',',  $fields);
		$strValues	= ':'.implode(',:', $fields);
		$sql = "INSERT IGNORE  INTO {$table}({$strFields}) VALUES({$strValues})";
		$isW = $this->Write($sql,$arrData);
		$lastInsertId = $this->getInsertId();
		return $isW!==FALSE ? ($lastInsertId ? $lastInsertId : true ) : FALSE; // 全等 === false 判断失败
	}

	public function insertUpdate( $arrData, $upData, $table=null){
		is_null($table) && $table = $this->table ;
		$fields		= array_keys($arrData);
		$strFields	= implode(',',  $fields);
		$strValues	= ':'.implode(',:', $fields);

		if(is_array($upData)){
			foreach($upData as $field=>$v){
				$arrFields[] = $field . '=:_' . $field;
				$arrUpdata['_'.$field] = $v;
			}
			$arrData += $arrUpdata;
			$strUpFields = implode(',', $arrFields);

		}else{
			$strUpFields = $upData;
		}

		$sql = "INSERT  INTO {$table}({$strFields}) VALUES({$strValues})  ON DUPLICATE KEY UPDATE {$strUpFields}";
		$isW = $this->Write($sql,$arrData);
		$lastInsertId = $this->getInsertId();
		return $isW!==FALSE ? ($lastInsertId ? $lastInsertId : true ) : FALSE;
	}

	public function insertAll($dataList,$table=null){
		is_null($table) && $table = $this->table ;
		$arrVal = array();
		foreach($dataList as $data){
			//$data		= array_map('mysql_escape_string', $data);
			$arrVal[]	= "('".implode("','", $data) . "')";
		}
		$strval = implode(',', $arrVal);

		$strFields	= implode(',', array_keys($dataList[0]));
		$sql		= "insert  into `$table` ($strFields) values{$strval}";
		$isW = $this->Write($sql,array());

		$lastInsertId = $this->getInsertId();
		return $isW!==FALSE ? ($lastInsertId ? $lastInsertId : true ) : FALSE; // 全等 === false 判断失败
	}

	public function table($table){
		$this->table = $table;
		return $this;
	}

	public function update(  $data, $where, $param=array(), $table=null){
		is_null($table) && $table = $this->table ;
		if( !is_array($param) ) return 'param must be array';
		$arrFields	= array();
		$arrParams  = array();
		foreach($data as $field=>$v){
			$arrFields[] = $field . '=:_' . $field;
			$arrParams['_'.$field] = $v;
		}
		$strFields = implode(',', $arrFields);


		foreach($param as $kp=>$vp){
			if( is_array($vp) ){  // in
				$arrInWhere = array();
				foreach($vp as $kd=>$vd){
					$ki = $kp . '_' . $kd;
					$arrInWhere[] = ':'. $ki;
					$arrParams[$ki] = $vd;
				}
				$where = str_replace(':'.$kp, implode(',', $arrInWhere), $where);
			}else{
				$arrParams[$kp] = $vp;
			}
		}

		$sql = "UPDATE {$table} SET {$strFields} WHERE  {$where}";
		$isW = $this->write($sql, $arrParams);
		return $isW===FALSE ? FALSE : true;	// 无论是否改变了数据，只要没报错，就算成功
	}

	public function createSelectSql(){
		$field =  isset($this->select->field) ?  $this->select->field  :  ' * ';
		$sql_no_cache = isset($this->select->sql_no_cache) ? $this->select->sql_no_cache  : '';
		$sql = 'SELECT ' . $sql_no_cache  . $field . ' FROM ' . $this->table;
		$sql .= isset($this->select->where) ? ' WHERE ' . $this->select->where : '';
		$sql .= isset($this->select->order) ? ' ORDER BY ' . $this->select->order : '';
		$sql .= isset($this->select->group) ? ' GROUP BY '. $this->select->group : '';
		$sql .= isset($this->select->limit) ? ' LIMIT '. $this->select->limit : '';
		//$this->select->sql = $sql;
		$this->select_param = isset($this->select->param) ? $this->select->param : array();
		$this->select = new \stdClass();
		return $sql;
	}

	public function sqlNoCache(){
		$this->select->sql_no_cache = ' SQL_NO_CACHE ';
		return $this;
	}

	public function from($table=''){
		$this->table = $table;
		return $this;
	}

	public function field($str='*'){
		$this->select->field = $str;
		return $this;
	}

	public function where($strSql, $paramData){
		foreach($paramData as $kp=>$vp){
			if( is_array($vp) ){  // in
				$arrInWhere = array();
				foreach($vp as $kd=>$vd){
					$ki = $kp . '_' . $kd;
					$arrInWhere[] = ':'. $ki;
					$this->select->param[$ki] = $vd;
				}
				$strSql = str_replace(':'.$kp, implode(',', $arrInWhere), $strSql);
			}else{
				$this->select->param[$kp] = $vp;
			}
		}

		$this->select->where = $strSql;
		return $this;
	}

	public function limit($offset, $lenth=null){
		$this->select->limit = $lenth==null ? (int)$offset : (int)$offset . ',' . (int)$lenth;
		return $this;
	}

	public function order($str){
		$this->select->order = $str;
		return $this;
	}

	public function group($str){
		$this->select->group = $str;
		return $this;
	}

	/*
	 * field 支持单个 字段，或者一个数组: 'user_num' (加1); array('user_num'=>1, 'cate_num'=>2)
	 * param 条件: array('id'=>1)
	 */
	public function increment( $field, $param, $table=null){
		if(is_array($field)){
			$sqlField = array();
			foreach($field as $kf=>$vn){
				$sqlField[] = $kf . '=' . $kf . '+'. $vn;
			}
			$strSqlField = implode(',', $sqlField);
		}else{
			$strSqlField = "{$field}={$field}+1";
		}
		is_null($table) && $table = $this->table ;
		$whereField = key($param);
		$where = 'WHERE ' .  $whereField. '=:'. $whereField;
		$sql = "UPDATE {$table} SET {$strSqlField} {$where}";

		$isW = $this->write($sql, $param);
		return $isW===FALSE ? FALSE : true;	// 无论是否改变了数据，只要没报错，就算成功
	}

	/*
	 * field 支持单个 字段，或者一个数组: 'user_num' (减1); array('user_num'=>1, 'cate_num'=>2)
	 * param 条件: array('id'=>1)
	 */
	public function decrement($field, $param,$table=null){
		if(is_array($field)){
			$sqlField = array();
			foreach($field as $kf=>$vn){
				$sqlField[] = $kf . '=' . $kf . '-'. $vn;
			}
			$strSqlField = implode(',', $sqlField);
		}else{
			$strSqlField = "{$field}={$field}-1";
		}
		is_null($table) && $table = $this->table ;
		$whereField = key($param);
		$where = 'WHERE ' .  $whereField. '=:'. $whereField;
		$sql = "UPDATE {$table} SET {$strSqlField} {$where}";

		$isW = $this->write($sql, $param);
		return $isW===FALSE ? FALSE : true;	// 无论是否改变了数据，只要没报错，就算成功
	}

	public function call($func, $params=array()){
		$p = array();
		foreach($params as $k=>$v){
			$p[]	= ':'. $k;
		}
		$strP = implode(',', $p);
		$sql = "CALL {$func}({$strP})";
		$isW = $this->write($sql, $params);
		$lastInsertId = $this->getInsertId();
		return $isW!==FALSE ? ($lastInsertId ? $lastInsertId : true ) : FALSE;
	}
}
