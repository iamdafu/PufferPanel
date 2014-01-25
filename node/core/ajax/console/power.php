<?php
/*
    PufferPanel - A Minecraft Server Management Panel
    Copyright (c) 2013 Dane Everitt
 
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
 
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
 
    You should have received a copy of the GNU General Public License
    along with this program.  If not, see http://www.gnu.org/licenses/.
 */
session_start();
require_once('../../../core/framework/framework.core.php');

if($core->framework->auth->isLoggedIn($_SERVER['REMOTE_ADDR'], $core->framework->auth->getCookie('pp_auth_token'), $core->framework->auth->getCookie('pp_server_hash')) === true){


	if(isset($_POST['process'])){

        /*
         * Get the Server Node Info
         */
        $query = $mysql->prepare("SELECT * FROM `nodes` WHERE `id` = :nodeid");
        $query->execute(array(
            ':nodeid' => $core->framework->server->getData('node')
        ));
        
        $node = $query->fetch();
        
		/*
		 * Verify that Server Port is set Correctly
		 */
		$con = ssh2_connect($node['sftp_ip'], 22);
		ssh2_auth_password($con, $core->framework->server->getData('ftp_user'), openssl_decrypt($core->framework->server->getData('ftp_pass'), 'AES-256-CBC', file_get_contents(HASH), 0, base64_decode($core->framework->server->getData('encryption_iv'))));
		$sftp = ssh2_sftp($con);
			
		/*
		 * Open Stream for Reading/Writing
		 */	
		$rewrite = false;							
		$stream = fopen("ssh2.sftp://".$sftp."/server/server.properties", 'r+');
		
			if(!$stream){
			
				/*
				 * Create server.properties
				 */
				$generateProperties = '
#Minecraft Server Properties
#Generated by PufferPanel
generator-settings=
op-permission-level=4
allow-nether=true
level-name=world
enable-query=true
allow-flight=false
announce-player-achievements=true
server-port='.$core->framework->server->getData('server_port').'
query.port='.$core->framework->server->getData('server_port').'
level-type=DEFAULT
enable-rcon=false
force-gamemode=false
level-seed=
server-ip='.$core->framework->server->getData('server_ip').'
max-build-height=256
spawn-npcs=true
white-list=false
debug=false
spawn-animals=true
texture-pack=
snooper-enabled=true
hardcore=false
online-mode=true
resource-pack=
pvp=true
difficulty=1
enable-command-block=false
gamemode=1
player-idle-timeout=0
max-players=20
spawn-monsters=true
generate-structures=true
view-distance=10
spawn-protection=16
motd=A Minecraft Server';

				
					if(!fwrite($stream, $generateProperties)){
			
                        $core->framework->log->getUrl()->addLog(3, 1, array('system.create_serverprops_failed', 'Unable to create a servers.properties file.'));
						exit('Unable to create new server.properties. Contact support ASAP.');
			
					}
				
                $core->framework->log->getUrl()->addLog(0, 1, array('system.create_serverprops', 'A new server.properties file was created for your server.'));						
			
			}
			
			/*
			 * Passed Inital Checks
			 */
			$contents = fread($stream, filesize("ssh2.sftp://".$sftp."/server/server.properties"));
			
			/*
			 * Generate Save File
			 */
			$saveDir = '/tmp/'.$core->framework->server->getData('hash').'/';
			if(!is_dir($saveDir)){
				mkdir($saveDir);
			}
			
			$fp = fopen($saveDir.'server.properties.savefile', 'w');
			fwrite($fp, $contents);
			fclose($fp);
			
			$newContents = $contents;
			fclose($stream);
			$lines = file($saveDir.'server.properties.savefile');
			
				foreach($lines as $line){
				
					$var = explode('=', $line);
					
						if($var[0] == 'server-port' && $var[1] != $core->framework->server->getData('server_port')){
							//Reset Port
							$newContents = str_replace('server-port='.$var[1], "server-port=".$core->framework->server->getData('server_port')."\n", $newContents);
							$rewrite = true;
						}else if($var[0] == 'online-mode' && $var[1] == 'false'){
							//Force Online Mode
							$newContents = str_replace('online-mode='.$var[1], "online-mode=true\n", $newContents);
							$rewrite = true;
						}else if($var[0] == 'enable-query' && $var[1] != 'true'){
							//Reset Query Port
							$newContents = str_replace('enable-query='.$var[1], "enable-query=true\n", $newContents);
							$rewrite = true;
						}else if($var[0] == 'query.port' && $var[1] != $core->framework->server->getData('server_port')){
							//Reset Query Port
							$newContents = str_replace('query.port='.$var[1], "query.port=".$core->framework->server->getData('server_port')."\n", $newContents);
							$rewrite = true;
						}else if($var[0] == 'server-ip' && $var[1] != $core->framework->server->getData('server_ip')){
							//Reset Query Port
							$newContents = str_replace('server-ip='.$var[1], "server-ip=".$core->framework->server->getData('server_ip')."\n", $newContents);
							$rewrite = true;
						}
				
				}
				
					/*
					 * Write New Data
					 */
					if($rewrite === true){
					
						$stream = fopen("ssh2.sftp://".$sftp."/server/server.properties", 'w+');
					
							if(!fwrite($stream, $newContents)){
					
                                $core->framework->log->getUrl()->addLog(3, 1, array('system.update_serverprops_failed', 'Unable to update the servers.properties file.'));
								exit('Unable to fix broken server.properties. Please contact support.');
					
							}
					
                        $core->framework->log->getUrl()->addLog(0, 0, array('system.serverprops_updated', 'The server properties file was updated to match the assigned information.'));
						fclose($stream);
						
					}

        /*
		 * Connect and Run Function
		 */
		$con = ssh2_connect($node['node_ip'], 22);
		ssh2_auth_password($con, $node['username'], openssl_decrypt($node['password'], 'AES-256-CBC', file_get_contents(HASH), 0, base64_decode($node['encryption_iv'])));
				
		if(isset($_POST['command'])){
			
			/*
			 * Query Dodads
			 */
			$online = true;
			try {
				$core->framework->query->connect($core->framework->server->getData('server_ip'), $core->framework->server->getData('server_port'));
			}catch(MinecraftQueryException $e){
				$online = false;
			}
			
			/*
			 * This Start Command is not working from PHP
			 */
			if($_POST['command'] == 'start'){
				
				if($online === true){
				
					$stream = ssh2_exec($con, 'exit');
					stream_set_blocking($stream, true);
					                            
					echo "Server is already running!";
					fclose($stream);
				
				}else{
										
					$stream = ssh2_exec($con, 'cd /srv/scripts; sudo ./start_server.sh "'.$core->framework->server->nodeData('server_dir').$core->framework->server->getData('ftp_user').'/server" "'.$core->framework->server->getData('max_ram').'" "'.$core->framework->server->getData('ftp_user').'"', true);
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    
                    $isError = stream_get_contents($errorStream);
                    if(!empty($isError))
                    	echo $isError;
                    
                    fclose($errorStream);
                    fclose($stream);
                    
                   	$core->framework->log->getUrl()->addLog(0, 1, array('user.server_start', 'The server `'.$core->framework->server->getData('name').'` was started.'));
                    
					echo "Server Started.";
											
				}
			
			}else if($_POST['command'] == 'stop'){
			
				if($online !== true){
				
					$stream = ssh2_exec($con, 'exit');
					stream_set_blocking($stream, true);
					
					echo "Server is already Stopped!";
					fclose($stream);
				
				}else{
				
					$stream = ssh2_exec($con, 'cd /srv/scripts; sudo ./send_command.sh "'.$core->framework->server->getData('ftp_user').'" "stop"', true);
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);

					$errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
					
					stream_set_blocking($errorStream, true);
					stream_set_blocking($stream, true);
					
					$isError = stream_get_contents($errorStream);
					if(!empty($isError))
						echo $isError;
					
					fclose($errorStream);
					fclose($stream);
                    
                    $core->framework->log->getUrl()->addLog(0, 1, array('user.server_stop', 'The server `'.$core->framework->server->getData('name').'` was stopped.'));
                    
					echo "Server Stopped.";
					
				}
			
			}else if($_POST['command'] == 'kill'){
			
				if($online !== true){
				
					$stream = ssh2_exec($con, 'exit');
					stream_set_blocking($stream, true);
					
					echo "Server is already Stopped!";
					fclose($stream);
				
				}else{
				
					$stream = ssh2_exec($con, 'cd /srv/scripts; sudo ./kill_server.sh "'.$core->framework->server->getData('ftp_user').'"', true);
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    
                    $isError = stream_get_contents($errorStream);
                    if(!empty($isError))
                    	echo $isError;
                    
                    fclose($errorStream);
                    fclose($stream);
                    
                    $core->framework->log->getUrl()->addLog(1, 1, array('user.server_kill', 'The server `'.$core->framework->server->getData('name').'` was forceably stopped.'));
                    
					echo "Server Killed.";
					
				}
			
			}else{
			
                $core->framework->log->getUrl()->addLog(0, 0, array('system.unspecified', 'An unknown error was encountered when trying to process a power function for the server.'));
				exit('Unknown.');
			
			}
			
		}
				
	}

}else{

	die('Invalid Authentication.');

}
?>
