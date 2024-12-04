<?php
class communicator{
    public static function command($line):void{
        if($line === "begin"){
            if(class_exists('communicator_server')){
                communicator_server::socketServer();
            }
        }
        elseif($line === "stop"){
            if(class_exists('communicator_client')){
                communicator_client::run('127.0.0.1', 8080, array("type"=>"stop","payload"=>""));
            }
        }
    }
    public static function init(){
        if(!settings::isset('name')){
            self::setName(gethostname());
        }
        if(!settings::isset('password')){
            self::setPassword("password");
        }
    }
    // Settings
    public static function setName(string $name):bool{
        return settings::set('name',$name,true);
    }
    public static function getName():string|bool{
        return settings::read('name');
    }
    public static function setPassword(string $password):bool{
        return settings::set('password',base64_encode($password),true);
    }
    public static function getPasswordEncoded():string|bool{
        return settings::read('password');
    }
    public static function verifyPassword(string $password):bool{
        return ($password === self::getPasswordEncoded());
    }
    // Data
    public static function send($stream, string $data):bool{
        $dataLength = strlen($data);
        if(strlen($dataLength) <= 20){
            if(fwrite($stream,$dataLength,20) !== false){
                if(fread($stream,2) === "OK"){
                    $sent = fwrite($stream,$data,$dataLength);
                    if($sent !== false){
                        if(fread($stream,2) === "OK"){
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }
    public static function receive($stream):string|bool{
        $responseLength = fread($stream,20);
        if($responseLength !== false){
            fwrite($stream,"OK",2);
            $responseLength = intval($responseLength);
            if($responseLength > 0){
                $response = "";
                while(strlen($response) < $responseLength){
                    $read = fread($stream,8192);
                    if($read !== false){
                        $response .= $read;
                    }
                    else{
                        break;
                    }
                }
                
                fwrite($stream,"OK",2);
                return $response;
                
            }
        }
        return false;
    }
    // Actions
    public static function close($stream):bool{
        return @fclose($stream);
    }
    public static function connect(string $ip, int $port, float|false $timeout, &$socketErrorCode, &$socketErrorString):mixed{
        if($timeout === false){
            $timeout = null;
        }
        return @stream_socket_client("tcp://$ip:$port", $socketErrorCode, $socketErrorString, $timeout);
    }
    public static function createServer(string $ip, int $port, int|false $timeout, &$socketErrorCode, &$socketErrorString):mixed{
        $socket = @stream_socket_server("tcp://$ip:$port", $socketErrorCode, $socketErrorString);
        if($socket === false){
            return false;
        }
        if($timeout !== false){
            if(@stream_set_timeout($socket, $timeout) === false){
                return false;
            }
        }
        return $socket;
    }
    public static function acceptConnection($socketServer, float|false $timeout):mixed{
        if($timeout !== false){
            $timeout = null;
        }
        return @stream_socket_accept($socketServer, $timeout);
    }
}