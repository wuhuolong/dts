<?php

function update_roomstate(&$roomdata, $runflag)
{
	eval(import_module('sys'));
	
	global $roomtypelist;
	$flag=1;
	for ($i=0; $i < room_get_vars($roomdata, 'pnum'); $i++)//人没满就不能开
		if (!$roomdata['player'][$i]['forbidden'] && $roomdata['player'][$i]['name']=='')
			$flag = 0;
	
	$changeflag = 0;
	if (!$runflag && $flag && $roomdata['roomstat']==0)
	{
		$roomdata['roomstat']=1;
		$roomdata['kicktime']=time()+30;
		$roomdata['timestamp']++;
		for ($i=0; $i<$roomtypelist[$roomdata['roomtype']]['pnum']; $i++) $roomdata['player'][$i]['ready']=0;
		$changeflag = 1;
	}
	
	if (!$flag) { $roomdata['roomstat']=0; $changeflag = 1; }
	return $changeflag;
}

function room_save_broadcast($roomid, &$roomdata)
{
	//保存数据并广播
	eval(import_module('sys'));
	$result = $db->query("SELECT groomstatus FROM {$gtablepre}game WHERE groomid = '$roomid'");
	$runflag = 0;
	if ($db->num_rows($result)) 
	{ 
		$rarr=$db->fetch_array($result); 
		if ($rarr['groomstatus']==2) $runflag = 1; 
	}
	
	update_roomstate($roomdata,$runflag);
	
	writeover(GAME_ROOT.'./gamedata/tmp/rooms/'.$roomid.'.txt', gencode($roomdata));
	$result = $db->query("SELECT * FROM {$gtablepre}roomlisteners WHERE roomid = '$roomid' AND timestamp < '{$roomdata['timestamp']}'");
	if ($db->num_rows($result))
	{
		$str='('; $lis=Array();
		while ($data=$db->fetch_array($result))
		{
			$str.="('".$data['port']."','".$data['roomid']."','".$data['timestamp']."','".$data['uniqid']."'),";
			array_push($lis,$data['port']);
		}
		$str=substr($str,0,-1).')';
		$db->query("DELETE FROM {$gtablepre}roomlisteners WHERE (port,roomid,timestamp,uniqid) IN $str");
		foreach ($lis as $port)
		{
			$___TEMP_socket=socket_create(AF_INET,SOCK_STREAM,SOL_TCP);  
			if ($___TEMP_socket===false) continue;
			$___TEMP_connected=socket_connect($___TEMP_socket,'127.0.0.1',$port);
			if (!$___TEMP_connected) continue;
			socket_shutdown($___TEMP_socket);
		}
	}
}
	
function room_init($roomtype)
{
	$a['roomtype']=$roomtype;
	//数据库中的groomstatus字段意义：
	//0 房间关闭（上局游戏已结束）
	//1 房间开启（游戏未开始）
	//2 房间开启（游戏已开始）
	
	//应和roomstat合并，改为：
	//0 房间关闭（上局游戏已结束）
	//10 房间开启，人数未满（游戏未开始）
	//20 房间开启，人数已满，倒计时（游戏未开始）
	//30 房间开启，游戏初始化（游戏未开始）
	//40 房间开启，游戏初始化完毕（游戏已开始）
	
	//roomstat在数据库status字段为1时才有意义
	//0 等待玩家
	//1 人数已满（等待所有玩家点击准备，并进入踢人倒计时）
	//2 即将开始（正在进行游戏初始化工作）
	
	$a['roomstat']=0;
	$a['roomfounder']='';
	
	//踢人时间，由使roomstat进入1的操作者负责设置
	$a['kicktime']=0;
	
	//各位置信息
	global $roomtypelist;
	$rdpnum = $roomtypelist[$roomtype]['pnum'];
	for ($i=0; $i<$rdpnum; $i++)
	{
		//在该位置的玩家名
		$s['name']='';
		//准备状态（roomstat=1有效，由使roomstat进入1的操作者负责重置）
		$s['ready']=0;
		//该位置是否被禁入
		$s['forbidden']=0;
		//该位置所属队伍（如等于该位置自身编号，则为队长，队长可以将本队其他位置设置禁入）
		$s['leader']=$roomtypelist[$roomtype]['leader-position'][$i];
		$a['player'][$i]=$s;
		unset($s);
	}
	
	//时间戳，用于更新
	$a['timestamp']=1;
	
	//最近10条聊天信息
	$a['chatdata']=Array();
	for ($i=0; $i<10; $i++)
	{
		$s['cid'] = -1;
		$s['data'] = '';
		$a['chatdata'][$i]=$s;
		unset($s);
	}
	return $a;
}

function fetch_roomdata(){
	eval(import_module('sys'));
	$rdata = Array();
	$result = $db->query("SELECT groomstatus,groomid,groomtype FROM {$gtablepre}game WHERE groomid > 0");
	while($rsingle = $db->fetch_array($result)){
		$rdata[] = $rsingle;
	}
	return $rdata;
}

//获得房间参数，如果房间数组没有就去$roomtypelist里找
function &room_get_vars(&$roomdata, $varname){
	global $roomtypelist;
	$r = NULL;
	if(isset($roomdata[$varname])) $r = &$roomdata[$varname];
	elseif(isset($roomtypelist[$roomdata['roomtype']][$varname])) $r = &$roomtypelist[$roomdata['roomtype']][$varname];
	return $r;
}

function room_gettype_from_gtype($gtype){
	global $roomtypelist;
	$r = NULL;
	foreach($roomtypelist as $rtk => $rtv){
		if($rtv['gtype'] == $gtype) {
			$r = $rtk;
			break;
		}
	}
	return $r;
}

//获得房间内玩家数和所需玩家数
function room_participant_get($roomdata){
	$rdplist = room_get_vars($roomdata, 'player');
	$rdpnum = $m = room_get_vars($roomdata, 'pnum');
	$p = 0;
	for ($i=0; $i < $rdpnum; $i++)
	{
		if ($rdplist[$i]['name']!='')
		{
			$p++;
		}
		else  if ($rdplist[$i]['forbidden'])
		{
			$m--;
		}
	}
	return array($p, $m);
}

//获得$user所在房间的位置，如果不在房间内则返回-1，没赋值$user的话默认用$cuser
function room_upos_check($roomdata, $user=NULL){
	//global $roomtypelist;
	if(!$user) {
		eval(import_module('sys'));
		$user = $cuser;
	}
	$upos = -1;
	$rdplist = room_get_vars($roomdata, 'player');
	$rdpnum = room_get_vars($roomdata, 'pnum');
	for ($i=0; $i < $rdpnum; $i++) {
		if (!$rdplist[$i]['forbidden'] && $rdplist[$i]['name']==$user){
			$upos = $i;
			break;
		}
	}
	return $upos;
}

//人数已满又长时间不准备的话自动踢人
function room_auto_kick_check(&$roomdata){
	$changed = 0;
	if (room_get_vars($roomdata, 'roomstat')==1 && time()>=room_get_vars($roomdata, 'kicktime'))
	{
		$rdplist = & room_get_vars($roomdata, 'player');
		$rdpnum = room_get_vars($roomdata, 'pnum');
		for ($i=0; $i < $rdpnum; $i++) 
			if (!$rdplist[$i]['forbidden'] && !$rdplist[$i]['ready'] && $rdplist[$i]['name']!='')
			{
				room_new_chat($roomdata,"<span class=\"grey\">{$rdplist[$i]['name']}因为长时间未准备，被系统踢出了位置。</span><br>");
				$rdplist[$i]['name']='';
			}
		$changed = 1;
	}
	return $changed;
}

//提供队长位置，把该队伍所有的位置设为开启
function room_refresh_team_pos(&$roomdata,$pos){
	//global $roomtypelist;
	$rdplist = & room_get_vars($roomdata, 'player');
	$rdpnum = room_get_vars($roomdata, 'pnum');
	for ($i=0; $i < $rdpnum; $i++)
		if ($pos == room_team_leader_check($roomdata,$i) && $rdplist[$i]['forbidden'])
			{
				$rdplist[$i]['forbidden']=0;
				$rdplist[$i]['name']='';
				$rdplist[$i]['ready']=0;
			}
}

//得到一个位置所属队伍的队长位置
function room_team_leader_check($roomdata,$pos) {
	//global $roomtypelist;
	return room_get_vars($roomdata, 'leader-position')[$pos];
}

function room_create($roomtype)
{
	eval(import_module('sys'));
	if ($disable_newgame || $disable_newroom) {
		gexit('系统维护中，暂时不开放新房间',__file__,__line__);
		die();
	}
	global $roomtypelist,$max_room_num;
	
	$roomtype=(int)$roomtype;
	if ($roomtype>=count($roomtypelist)){
		gexit('房间参数错误',__file__,__line__);
		die();
	}
	$rchoice = -1;
	$rsetting = $roomtypelist[$roomtype];
	$rdata = fetch_roomdata();
	if($rsetting['soleroom']){//永续房特判
		$rid = -1;
		$rids = range(1,$max_room_num);
		foreach($rdata as $rd){
			$rid = $rd['groomid'];
			$rids = array_diff($rids, Array($rid));
			if($rd['groomtype'] == $roomtype && $rd['groomstatus'] == 2){//永续房存在的情况下直接进
				$rchoice = $rid;
				break;
			}elseif($rd['groomstatus'] == 0){//房间关闭状态，改成永续房
				$rchoice = $rid;
				$db->query("UPDATE {$gtablepre}game SET groomstatus = 1, groomtype = '$roomtype' WHERE groomid = '$rid'");
				break;
			}
		}
		if(!empty($rids) && $rchoice < 0){//否则新建房间
			$rchoice = $rids[0];
			$db->query("INSERT INTO {$gtablepre}game (groomid,groomstatus,groomtype) VALUES ('$rchoice',1,'$roomtype')");
			//$db->query("UPDATE {$gtablepre}rooms SET status = 1, roomtype = '$roomtype' WHERE roomid = '$rid'");
		}
	}else{
		$result = $db->query("SELECT groomid, groomtype, groomstatus FROM {$gtablepre}game ORDER BY groomid");
		$soleroomnum = 0;
		$max_room_num_temp = $max_room_num;
		$roomarr = array();
		while($rrs = $db->fetch_array($result)){
			$rrsid = $rrs['groomid'];
			$roomarr[$rrsid] = $rrs;
			if($roomtypelist[$rrs['groomtype']]['soleroom']) {
				$max_room_num_temp++;
			}else{//很丑陋，对吧？我也这么想。分明全部丢进数据库就可以的东西，为什么一定要写文件？
				$file = GAME_ROOT.'./gamedata/tmp/rooms/'.$rrsid.'.txt';
				//writeover('a.txt',$file,'ab+');
				if(file_exists($file)){
					$rfdata = gdecode(file_get_contents($file),1);
				}
				if(isset($rfdata['roomfounder']) && $rfdata['roomfounder']==$cuser){
					gexit("你已经创建了房间{$rrsid}，请在该房间游戏结束后再尝试创建房间",__file__,__line__);
					die();
				}
			}
		}
		for ($i=1; $i<=$max_room_num_temp; $i++)
		{
			if(!isset($roomarr[$i])) 
			{
				$db->query("INSERT INTO {$gtablepre}game (gamestate,groomid,groomstatus,groomtype) VALUES (0,'$i',1,'$roomtype')");
				$rchoice = $i; break;
			}
			else 
			{
				if ($roomarr[$i]['groomstatus']==0)
				{
					$db->query("UPDATE {$gtablepre}game SET gamestate = 0, groomstatus = 1, groomtype = '$roomtype' WHERE groomid = '$i'");
					$rchoice = $i; break;
				}
			}
		}
	}	
	if ($rchoice == -1)
	{
		gexit('房间数目已经达到上限，请加入一个已存在的房间',__file__,__line__);
		die();
	}
	//房间等待变量初始化（对应文件）
	$roomdata = room_init($roomtype);
	//房间数据库初始化（对应数据库）
	room_init_db_process($rchoice);
	$roomdata['player'][0]['name']=$cuser;
	$roomdata['roomfounder']=$cuser;
	writeover(GAME_ROOT.'./gamedata/tmp/rooms/'.$rchoice.'.txt', gencode($roomdata));
	$db->query("DELETE from {$gtablepre}roomlisteners WHERE roomid = '$rchoice'"); 
//	if($rsetting['soleroom']){
//		room_enter($rchoice);
//	}
	return $rchoice;
}

function room_new_chat(&$roomdata,$str)
{
	for ($i=1; $i<=9; $i++) $roomdata['chatdata'][$i-1]=$roomdata['chatdata'][$i];
	$roomdata['chatdata'][9]['cid']=max($roomdata['chatdata'][8]['cid'],0)+1;
	$roomdata['chatdata'][9]['data']=$str;
	$roomdata['timestamp']++;
}

function room_enter($id)
{
	eval(import_module('sys'));
//	if ($disable_newgame || $disable_newroom) {
//		gexit('管理员禁止了加入房间',__file__,__line__);
//		die();
//	}
	$id=(int)$id;
	$result = $db->query("SELECT groomstatus,groomtype FROM {$gtablepre}game WHERE groomid = '$id'");
	if(!$db->num_rows($result)) 
	{
		gexit('房间编号'.$id.'不存在',__file__,__line__);
		die();
	}
	$rd=$db->fetch_array($result);
	if ($rd['groomstatus']==0)
	{
		gexit('房间编号'.$id.'不存在',__file__,__line__);
		die();
	}
	
	if (!file_exists(GAME_ROOT.'./gamedata/tmp/rooms/'.$id.'.txt')) 
	{
		gexit('房间编号'.$id.'不存在',__file__,__line__);
		die();
	}
	$header = 'index.php';
	$roomdata = gdecode(file_get_contents(GAME_ROOT.'./gamedata/tmp/rooms/'.$id.'.txt'),1);
	//global $cuser;
	global $roomtypelist, $gametype, $startime, $now, $room_prefix, $alivenum, $soleroom_resettime;
	if($roomtypelist[$rd['groomtype']]['soleroom']){//永续房，绕过其他判断直接进房间
		//以后得改改
		if ($disable_newgame || $disable_newroom) {
			gexit('系统维护中，暂时不能加入房间',__file__,__line__);
			die();
		}
		$room_prefix = 's'.$id;
		$room_id = $id;
		//$tablepre = $gtablepre.$room_prefix.'_';
		$tablepre = \sys\get_tablepre();
		$wtablepre = $gtablepre.($room_prefix[0]);
		\sys\load_gameinfo();
		$init_state = room_init_db_process($room_id); //\sys\room_auto_init();
		$need_reset = $rd['groomstatus'] == 1 ? true : false;//未开始则启动房间
		//writeover('a.txt',$init_state);
		if(!($init_state & 4)){//读取最后有玩家行动的时间，如果超时则需要重置，防止房间各种记录飙得太长
			//writeover('a.txt',50);
			$result = $db->query("SELECT endtime FROM {$tablepre}players WHERE type=0 ORDER BY endtime DESC LIMIT 1");
			if($db->num_rows($result)){
				$lastendtime = $db->fetch_array($result)['endtime'];				
				if($now - $lastendtime > $soleroom_resettime) $need_reset = 1;
			}
		}
		if($need_reset){	
			//$db->query("UPDATE {$gtablepre}game SET groomstatus = 2 WHERE groomid = '$id'");
			$groomstatus = 2;
			$gamestate = 0;
			$gametype = $roomtypelist[$rd['groomtype']]['gtype'];
			$starttime = $now;
			\sys\save_gameinfo(0);
			\sys\routine();
		}
		$pname = (string)$cuser;
		$result = $db->query("SELECT * FROM {$gtablepre}users WHERE username = '$pname' LIMIT 1");
		$udata = $db->fetch_array($result);
		$result = $db->query("SELECT * FROM {$tablepre}players WHERE name = '$pname' AND type = 0");
		if(!$db->num_rows($result)){//从未进入过则直接进入战场
			include_once GAME_ROOT.'./include/valid.func.php';
			enter_battlefield($udata['username'],$udata['password'],$udata['gender'],$udata['icon'],$pcard);
		}else{//进过的话，离开超过1分钟则清空数据从头开始
			$pdata = $db->fetch_array($result);
			$ppid = $pdata['pid'];
			$pendtime = $pdata['endtime'];
			if($now - $pendtime > 60){
				$db->query("DELETE FROM {$tablepre}players WHERE name = '$pname' AND type = 0");
				$db->query("DELETE FROM {$tablepre}players WHERE type>0 AND teamID = '$ppid'");
				$alivenum --;
				include_once GAME_ROOT.'./include/valid.func.php';
				enter_battlefield($udata['username'],$udata['password'],$udata['gender'],$udata['icon'],$pcard);
			}
		}
		$header = 'game.php';
	}
	room_new_chat($roomdata,"<span class=\"grey\">{$cuser}进入了房间</span><br>");
	$db->query("UPDATE {$gtablepre}users SET roomid = 's{$id}' WHERE username = '$cuser'");
	room_save_broadcast($id,$roomdata);
	header('Location: '.$header);
	die();
}
	
function room_showdata($roomdata, $user)
{
	global $roomid;
	include GAME_ROOT.'./include/roommng/roommng.config.php';
	$upos = room_upos_check($roomdata, $user);
//	$upos = -1;
//	for ($i=0; $i<$roomtypelist[$roomdata['roomtype']]['pnum']; $i++)
//		if (!$roomdata['player'][$i]['forbidden'] && $roomdata['player'][$i]['name']==$user)
//			$upos = $i;
			
	ob_clean();
	ob_start();
	include template('roommain');
	$gamedata['innerHTML']['roommain'] = ob_get_contents();
	if ($roomdata['roomstat']==2) $gamedata['innerHTML']['roomchatarea'] = '<div></div>';
	$gamedata['value']['timestamp'] = $roomdata['timestamp'];
	if ($roomdata['roomstat']!=2) $gamedata['lastchat']=$roomdata['chatdata'];
	ob_clean();
	echo gencode($gamedata);
}
	
function room_getteamhtml(&$roomdata, $u)
{
	global $roomtypelist;
	$str='';
	$rdplist = room_get_vars($roomdata, 'player');
	$rdpnum = room_get_vars($roomdata, 'pnum');
	for ($i=0; $i < $rdpnum; $i++)
		if (!$rdplist[$i]['forbidden'] && $rdplist[$i]['name']!='' && room_get_vars($roomdata, 'leader-position')[$i]==$u)
		{
			$str.=$rdplist[$i]['name'].',';
		}
	if ($str!='') $str=substr($str,0,-1);
	return $str;
}

function room_init_db_process($room_id){
	if (eval(__MAGIC__)) return $___RET_VALUE;
	global $gtablepre,$db;
	$room_prefix = 's'.$room_id;
	$init_state = 0;
	
	$tablepre = \sys\get_tablepre();
	$wtablepre = $gtablepre.'s';
	//$tablepre = $gtablepre.$room_prefix.'_';
	//创建对应类型的优胜列表
	$result = $db->query("SHOW TABLES LIKE '{$wtablepre}winners';");
	if (!$db->num_rows($result))
	{
		$db->query("CREATE TABLE IF NOT EXISTS {$wtablepre}winners LIKE {$gtablepre}winners;");
		$db->query("INSERT INTO {$wtablepre}winners (gid) VALUES (0);");
		$init_state += 1;
	}
	
	//如果该房间对应的gameinfo不存在，则插入
	//实际上不需要，因为在调用这个之前就已经插入了
//	$result = $db->query("SELECT gamestate FROM {$gtablepre}game WHERE groomid = '$room_id'");
//	$r1 = $db->num_rows($result);
//	if (!$r1)
//	{
//		$db->query("INSERT INTO {$gtablepre}game (groomid) VALUES ('$room_id')");
//		$init_state += 2;
//	}

	//如果该房间对应的各数据表不存在（以players表为判断依据），则创建
	$result = $db->query("SHOW TABLES LIKE '{$tablepre}players';");
	$r2 = $db->num_rows($result);
	if (!$r2)
	{
		$sql = file_get_contents(GAME_ROOT.'./gamedata/sql/reset.sql');
		$sql = str_replace("\r", "\n", str_replace(' bra_', ' '.$tablepre, $sql));
		$db->queries($sql);
		
		$sql = file_get_contents(GAME_ROOT.'./gamedata/sql/players.sql');
		$sql = str_replace("\r", "\n", str_replace(' bra_', ' '.$tablepre, $sql));
		$db->queries($sql);
		
		$sql = file_get_contents(GAME_ROOT.'./gamedata/sql/shopitem.sql');
		$sql = str_replace("\r", "\n", str_replace(' bra_', ' '.$tablepre, $sql));
		$db->queries($sql);
		$init_state += 4;
	}
	return $init_state;
}

/* End of file roommng.func.php */
/* Location: /include/roommng/roommng.func.php */