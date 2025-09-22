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
            $hostname = gethostname();
            if(!is_string($hostname)){
                mklog(2, 'Failed to get pc name, setting it to unknown');
                $hostname = 'unknown';
            }
            if(!settings::set('name', $hostname)){
                mklog(2, 'Failed to set pc name');
            }
        }
        if(!settings::isset('password')){
            echo "Communicator has no password set, would you like to set this?\n";
            if(user_input::yesNo()){
                retry:
                echo "Please enter a password\n";
                $pass1 = user_input::await();
                echo "Please repeat the password\n";
                $pass2 = user_input::await();
                if($pass1 !== $pass2){
                    echo "The passwords did not match, try again?\n";
                    if(user_input::yesNo()){
                        goto retry;
                    }
                    else{
                        goto defpass;
                    }
                }
                else{
                    $password = $pass1;
                }
            }
            else{
                defpass:
                echo "Setting communicator password to \"password\"\n";
                $password = 'password';
            }

            if(!settings::set('password', base64_encode($password))){
                mklog(2, 'Failed to set password');
            }
        }

        $defaultSettings = [
            'whitelist' => [],
            'whitelistEnabled' => false,
            'blacklist' => ['unknown'],
        ];

        foreach($defaultSettings as $settingName => $settingValue){
            if(!settings::isset($settingName)){
                if(!settings::set($settingName, $settingValue)){
                    mklog(2, 'Failed to set setting ' . $settingName);
                }
            }
        }
    }
    // Settings
    public static function getName():string|bool{
        return settings::read('name');
    }
    public static function setPassword(string $password, string $oldPassword):bool{
        if(!self::verifyPassword($oldPassword)){
            mklog(2, 'Failed to set password as an incorrect old password was provided');
            return false;
        }
        return settings::set('password', base64_encode($password), true);
    }
    public static function getPasswordEncoded():string|bool{
        return settings::read('password');
    }
    public static function verifyPassword(string $encodedPassword):bool{
        return (self::getPasswordEncoded() === $encodedPassword);
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
    public static function sendData($stream, mixed $data, bool $auth=true):bool{
        if(!is_resource($stream)){
            return false;
        }

        $message['name'] = communicator::getName();
        if(!is_string($message['name'])){
            mklog(2, 'Failed to get communicator name');
            return false;
        }

        if($auth){
            $message['password'] = communicator::getPasswordEncoded();
            if(!is_string($message['password'])){
                mklog(2, 'Failed to get encoded communicator password');
                return false;
            }
        }

        $message['time'] = time();
        $message['data'] = $data;

        $message = json_encode($message);
        if(!is_string($message)){
            mklog(2, 'Failed to encode data');
            return false;
        }

        $message = base64_encode($message);

        return self::send($stream, $message);
    }
    public static function receiveData($stream, bool $auth=true):mixed{
        if(!is_resource($stream)){
            return false;
        }

        $message = self::receive($stream);
        if(!is_string($message)){
            mklog(2, 'Failed to receive data');
            return false;
        }

        $message = base64_decode($message);
        if(!is_string($message)){
            mklog(2, 'Failed to decode message (base64)');
            return false;
        }

        $message = json_decode($message, true);
        if(!is_array($message)){
            mklog(2, 'Failed to decode message (json)');
            return false;
        }

        if(!isset($message['name']) || !is_string($message['name'])){
            mklog(2, 'Message sender did not send a name');
            return false;
        }

        if(settings::read('whitelistEnabled')){
            $whitelist = settings::read('whitelist');
            if(!is_array($whitelist)){
                mklog(2, 'Failed to read whitelist');
                return false;
            }
            if(!in_array(strtolower($message['name']), $whitelist)){
                mklog(2, 'Message sender not in whitelist');
                return false;
            }
        }

        $blacklist = settings::read('blacklist');
        if(!is_array($blacklist)){
            mklog(2, 'Failed to read blacklist');
            return false;
        }
        if(in_array(strtolower($message['name']), $blacklist)){
            mklog(2, 'Message sender in blacklist');
            return false;
        }

        if($auth){
            if(!isset($message['password']) || !is_string($message['password'])){
                mklog(2, 'Message sender did not send authentication');
                return false;
            }
            if(!self::verifyPassword($message['password'])){
                mklog(2, 'Message sender attached incorrect authentication');
                return false;
            }
        }

        if(!isset($message['data'])){
            mklog(2, 'Message sender did not attach any data');
            return false;
        }

        return $message['data'];
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
        if(!is_float($timeout)){
            $timeout = null;
        }
        return @stream_socket_accept($socketServer, $timeout);
    }
}