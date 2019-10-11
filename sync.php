<?php

header("Content-type: text/plain; charset=utf-8");
date_default_timezone_set("PRC");
set_time_limit(0);

defined('DS') or define('DS', DIRECTORY_SEPARATOR);
defined('APP_ROOT') or define('APP_ROOT', __DIR__ . DS);

class Sync
{
    // 客户端
    public static function client($config)
    {
        DB::$config = $config['db'];

        $ms = microtime(true) * 1000;
        echo '===检测本地版本===' . PHP_EOL;
        
        // 获取本地表版本信息
        $table_list = [];
        if (file_exists('client.json'))
        {
            $file_content = file_get_contents('client.json');
            $table_list = json_decode($file_content, true);
        }
        else
        {
            $table_list = $config['table_list'];
            foreach($table_list as &$table)
            {
                echo '检测 `' . $table['table'] . '`表 ';
                switch ($table['type'])
                {
                    case 'full':
                        $list = DB::query("SELECT * FROM " . $table['table'] . ' ORDER BY id');
                        $table['mark'] = md5(json_encode($list));
                        break;
                    case 'id':
                        $max = DB::queryObject("SELECT MAX(id) AS max_id FROM " . $table['table']);
                        $table['mark'] = empty($max) || empty($max['max_id']) ? 0 : $max['max_id'];
                        break;
                    case 'sync_id':
                        $max = DB::queryObject("SELECT MAX(sync_id) AS max_id FROM " . $table['table']);
                        $table['mark'] = empty($max) || empty($max['max_id']) ? 0 : $max['max_id'];
                        break;
                }
            
                echo '【MARK: ' . $table['mark'] . '】' . PHP_EOL;
            }
            unset($table);

            file_put_contents('client.json', json_encode($table_list));
        }
        
        echo PHP_EOL . '===开始请求数据===' . PHP_EOL;
        $post_data = [
            "sid" => $config['sid'],
            "key" => md5($config['sid'] . '_' . date('Ymd') . '_' . $config['key']),
            "table_list" => json_encode($table_list),
        ];
        
        $res_data = Tool::postUrl($config['server_url'], $post_data);
        
        if (empty($res_data))
        {
            echo 'ERROR:无数据或网络异常！';
            return;
        }
        
        if (stripos($res_data, 'ERROR:'))
        {
            echo $res_data;
            return;
        }
        
        $res_table_list = json_decode($res_data, true);
        
        if (empty($res_table_list))
        {
            echo 'JSON异常或无数据：' . PHP_EOL . $res_data;
            return;
        }
        
        echo '获取字节数：' . round(strlen($res_data) / 1024) . 'kb' . PHP_EOL;
        
        echo PHP_EOL . "===开始同步数据===" . PHP_EOL;
        $count = 0;
        foreach($res_table_list as $table)
        {
            echo '同步 `' . $table['table'] . '`表 ';
            
            switch ($table['type'])
            {
                case 'full':
                    if (!empty($table['list']))
                    {
                        DB::query("DELETE FROM " . $table['table']);
                        foreach ($table['list'] as $rr)
                        {
                            DB::insert($table['table'], $rr);
                        }
                    }
                    break;
                case 'id':
                case 'sync_id':
                    foreach ($table['list'] as $r)
                    {
                        DB::insert($table['table'], $r);
                    }
                    break;
            }
            
            if (!empty($table['list']))
            {
                echo '共' . count($table['list']) . "条数据";
            }
            echo PHP_EOL;
        
            $count += count($table['list']);
        }

        if ($count)
        {
            unlink('client.json');
        }
        
        $log = '同步完成 ' . $count . ' => ' . number_format(microtime(true) * 1000 - $ms) . 'ms';
        echo PHP_EOL . $log . PHP_EOL;
        
        Tool::add_log($log);
    }

    // 服务器端
    public static function server($config)
    {
        DB::$config = $config['db'];

        // 参数验证
        $key_list = ['sid', 'key', 'table_list'];
        foreach ($key_list as $r)
        {
            if (!isset($_POST[$r]))
            {
                echo 'ERROR: 缺少参数 ' . $r;
                return;
            }
        }

        $sid = $_POST['sid'];
        if (empty($config['sid_list'][$sid]))
        {
            echo 'ERROR: ' . $sid . '未授权';
            return;
        }

        $key_md5 = md5($sid . '_' . date('Ymd') . '_' . $config['sid_list'][$sid]);
        if ($_POST['key'] != $key_md5)
        {
            echo 'ERROR: KEY验证失败';
            return;
        }

        // 获取同步数据
        $table_list = json_decode($_POST['table_list'], true);
        foreach($table_list as &$r)
        {
            $table_name = $r['table'];
            
            switch ($r['type'])
            {
                case 'full':
                    $r['list'] = [];
                    $list = DB::query("SELECT * FROM $table_name ORDER BY id");
                    if ($r['mark'] != md5(json_encode($list)))
                    {
                        $r['list'] = $list;
                    }
                    break;
                case 'id':
                    $id = $r['mark'];
                    $r['list'] = DB::query("SELECT * FROM $table_name WHERE id > $id ORDER BY id LIMIT 1000");
                    break;
                case 'sync_id':
                    $id = $r['mark'];
                    $r['list'] = DB::query("SELECT * FROM $table_name WHERE sync_id > $id ORDER BY sync_id LIMIT 1000");
                    break;
            }

            Tool::add_log($table_name . ':' . count($r['list']));
        }
        unset($r);

        ob_clean();
        echo json_encode($table_list);
    }
}

class Tool
{
	public static function now()
	{
		return date('Y-m-d H:i:s');
	}

	// 循环检测并创建文件夹
	public static function create_dir($path)
	{
		if (!file_exists($path))
		{
			self::create_dir(dirname($path));
			mkdir($path, 0777);
		}
	}

	// 写入文件日志
	public static function add_log($content, $type = 'log')
	{
		$file = APP_ROOT . DS . 'log' . DS . $type . '_' . date('Ymd') . '.log';

		$str = date('Y-m-d H:i:s ') . $content . PHP_EOL;
		@file_put_contents($file, $str, FILE_APPEND);
    }
    
	/**
	 * post提交数据
	 */
    public static function postUrl($url, $params = [])
    {
        $ch = curl_init();//初始化curl
        curl_setopt($ch, CURLOPT_URL, $url);//抓取指定网页
        curl_setopt($ch, CURLOPT_HEADER, 0);//设置header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

        $html = null;
        try
        {
            $html = curl_exec($ch);
            curl_close($ch);
        }
        catch (Exception $ex)
        {
			$html = 'ERROR';
        }

        return $html;
    }
}

class DB
{
	//pdo对象
	public $con = NULL;
	public static $config = NULL;

	function DB()
	{
		$db = DB::$config;
		
		$this->con = new PDO($db['dsn'], $db['user'], $db['password'], array(
			PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES `utf8`',
			PDO::ATTR_PERSISTENT => TRUE,
		));

		$this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->con->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
	}

	/*
	 * 执行SQL操作
	 */
	function runSql($sql, $para = NULL)
	{
		$sql = trim($sql);

		$arr = explode(' ', $sql);
		$sqlType = strtoupper($arr[0]);

		if (strpos($sql, 'INTO OUTFILE') !== FALSE)
		{
			$sqlType = 'OUTFILE';
		}

		try
		{
			$cmd = $this->con->prepare($sql);
			if ($para == NULL)
			{
				$cmd->execute();
			}
			else
			{
				$cmd->execute($para);
			}
		}
		catch (Exception $ex)
		{
			Tool::add_log('SQL ERROR:' . $ex -> getMessage() . PHP_EOL . $sql);
			throw $ex;
		}

		$return = NULL;
		if($sqlType == "SELECT" || $sqlType == "SHOW")
		{
			$return = $cmd->fetchAll(PDO::FETCH_ASSOC);
		}
		else if($sqlType == "INSERT")
		{
			$return = $this->con->lastInsertId();
		}
		else
		{
			$return = $cmd->rowCount();
		}

		return $return;
	}

	/*
	 * 执行SQL操作 返回列表
	 */
	public static function query($sql, $para = NULL)
	{
		$db = new DB();
		$res = $db->runSql($sql, $para);
		$db = NULL;

		return $res;
	}

	/*
	 * 执行SQL操作 返回一个对象
	*/
	public static function queryObject($sql, $para = NULL)
	{
		$db = new DB();
		$list = $db->runSql($sql, $para);
		$db = NULL;

		if ($list === false)
		{
			return null;
		}

		return count($list) > 0 ? $list[0] : null;
	}

	/*
	* 判断数据库表是否存在
	*/
	public static function tableExist($table)
	{
		$db = new DB();
		$list = $db->runSql("show tables like '" . $table . "'");
		$db = NULL;

		return count($list) > 0;
	}

	/*
	 * 通过ID获取
	 */
	public static function getById($table, $id)
	{
		return DB::queryObject("SELECT * FROM $table WHERE `id` = :id", array('id' => $id));
	}

	/*
	 * 通过ID删除
	 */
	public static function delById($table, $id)
	{
		return DB::query("DELETE FROM $table WHERE `id` = :id", array('id' => $id));
	}

	/*
	 * 添加
	 */
	public static function insert($table, $para)
	{
		$sql_para = array();

		foreach ($para as $k => $v)
		{
			$sql_para[] = $k;
		}

		$res = DB::query("REPLACE INTO `" . $table . "` (`" . implode("`, `", $sql_para) . "`)
				VALUES(:" . implode(", :", $sql_para) . ")", $para);

		return $res;
	}

	/*
	 * 更新
	 */
	public static function update($table, $para)
	{
		$sql_para = array();

		foreach ($para as $k => $v)
		{
			if ($k == 'id')
			{
				continue;
			}
			$sql_para[] = '`' . $k . '` = :' . $k;
		}

		$res = DB::query("UPDATE `" . $table . "` SET " . implode(", ", $sql_para) . " WHERE `id` = :id", $para);

		return $res;
	}

	/*
	 * 获取个数
	 */
	public static function value($sql, $para = NULL)
	{
		$item = DB::queryObject($sql, $para);

		$val = null;
		if ($item != NULL)
		{
			$val = array_values($item);
			$val = $val[0];
		}

		return $val;
	}
}