<?php
class communicator{
    private static $lastReceivedName = "";
    private static $hostname = "unknown";
    private static $passwordEncoded = "";
    private static $blacklist = [];
    private static $whitelist = [];
    private static $whitelistEnabled = false;
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
    public static function init():void{
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

        if(!extensions::ensure("sockets")){
            mklog(2, "Sockets extension is not enabled, an attempt has been made to enable it, please restart for the change to take effect.");
        }

        $hostname = settings::read('name');
        if(is_string($hostname) && !empty($hostname)){self::$hostname = $hostname;}
        else{mklog(2, "Failed to load name");}

        $passwordEncoded = settings::read('password');
        if(is_string($passwordEncoded)){self::$passwordEncoded = $passwordEncoded;}
        else{mklog(2, "Failed to check encoded password");}

        $blacklist = settings::read('blacklist');
        if(is_array($blacklist)){self::$blacklist = $blacklist;}
        else{mklog(2, "Failed to load blacklist");}
        
        $whitelist = settings::read('whitelist');
        if(is_array($whitelist)){self::$whitelist = $whitelist;}
        else{mklog(2, "Failed to load whitelist");}

        $whitelistEnabled = settings::read('whitelistEnabled');
        if(is_bool($whitelistEnabled)){self::$whitelistEnabled = $whitelistEnabled;}
        else{mklog(2, "Failed to read whitelistEnabled");}
    }
    // Settings
    public static function getName():string{
        return self::$hostname;
    }
    public static function setPassword(string $password, string $oldPassword):bool{
        if(!self::verifyPassword($oldPassword)){
            mklog(2, 'Failed to set password as an incorrect old password was provided');
            return false;
        }
        return settings::set('password', base64_encode($password), true);
    }
    public static function getPasswordEncoded():string{
        return self::$passwordEncoded;
    }
    public static function verifyPassword(string $encodedPassword):bool{
        return (self::getPasswordEncoded() === $encodedPassword);
    }
    // Data
    public static function send(Socket $socket, string $data):bool{
        $dataLength = strlen($data); //int is 19 digits
        if(!socket_write($socket, (string) $dataLength, 20)){
            return false;
        }

        if(socket_read($socket, 2, PHP_BINARY_READ) !== "OK"){
            return false;
        }

        $totalSent = 0;
        while($totalSent < $dataLength){
            $sent = socket_write($socket, substr($data, $totalSent), 8192);
            if(!$sent){
                echo "Failed to write any bytes to stream\n";
                return false;
            }
            $totalSent += $sent;
        }

        return socket_read($socket, 2, PHP_BINARY_READ) === "OK";
    }
    public static function receive(Socket $socket):?string{
        $responseLength = socket_read($socket, 20);
        if(!$responseLength){//also checks for string of "0"
            return null;
        }

        socket_write($socket, "OK", 2);
        
        $response = "";
        while(strlen($response) < $responseLength){
            $read = socket_read($socket, 8192, PHP_BINARY_READ);
            if($read !== false){
                $response .= $read;
            }
            else{
                break;
            }
        }
        
        socket_write($socket, "OK", 2);
        return $response;

    }
    public static function sendData(Socket $socket, mixed $data, bool $auth=true):bool{
        $message['name'] = self::getName();
        if(!is_string($message['name'])){
            mklog(2, 'Failed to get communicator name');
            return false;
        }

        if($auth){
            $message['password'] = self::getPasswordEncoded();
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

        return self::send($socket, $message);
    }
    public static function receiveData(Socket $socket, bool $auth=true):mixed{
        $message = self::receive($socket);
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

        self::$lastReceivedName = $message['name'];

        if(self::$whitelistEnabled){
            if(!in_array(strtolower($message['name']), self::$whitelist)){
                mklog(2, 'Message sender not in whitelist');
                return false;
            }
        }

        
        if(in_array(strtolower($message['name']), self::$blacklist)){
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
    public static function sendFromFile(Socket $socket, string $file, bool $showProgress=true, int $chunkSize=262144):bool{
        if(!is_file($file)){
            mklog(2, "Input file does not exist");
            return false;
        }

        $size = filesize($file);
        if(!$size){
            mklog(2, "Failed to get file size");
            return false;
        }

        $file = fopen($file, "rb");
        if(!is_resource($file)){
            mklog(2, "Failed to open file");
            return false;
        }

        if(!self::send($socket, "fileSendStart")){
            mklog(2, "Failed to send initial message");
            @fclose($file);
            return false;
        }

        if(!self::send($socket, $size)){
            mklog(2, "Failed to send file size");
            @fclose($file);
            return false;
        }

        $total = 0;
        $lastStatus = microtime(true);

        while(!feof($file)){
            $chunk = fread($file, $chunkSize);
            if(!is_string($chunk)){
                mklog(2, "Failed to read chunk");
                @fclose($file);
                return false;
            }

            $currentChunkSize = strlen($chunk);

            if(!self::send($socket, $chunk)){
                mklog(2, "Failed to send chunk");
                @fclose($file);
                return false;
            }

            if($showProgress){
                $total += $currentChunkSize;
                if(microtime(true) - $lastStatus > 0.1){
                    echo files::progressTracker($size, $total);
                    $lastStatus = microtime(true);
                }
            }
        }

        @fclose($file);

        if(self::receive($socket) !== "OK"){
            mklog(2, "Receiver failed to receive all chunks");
            return false;
        }

        return true;
    }
    public static function receiveFile(Socket $socket, string $fileName, bool $showProgress=true, bool $overwrite=true):bool{
        if(is_file($fileName) && !$overwrite){
            mklog(2, "File allready exists");
            return false;
        }

        if(!files::mkFile($fileName, "", "wb", true)){
            mklog(2, "Failed to create destination file");
            return false;
        }

        if(self::receive($socket) !== "fileSendStart"){
            mklog(2, "Did not receive file send initiation");
            return false;
        }

        $total = intval(self::receive($socket));
        if($total < 1){
            mklog(2, "Did not receive file size");
            return false;
        }

        $file = fopen($fileName, "wb");
        if(!is_resource($file)){
            mklog(2, "Failed to open destination file");
            return false;
        }

        $current = 0;
        $lastStatus = microtime(true);

        while($current < $total){
            $chunk = self::receive($socket);
            if(!is_string($chunk)){
                mklog(2, "Failed to receive a chunk");
                @fclose($file);
                return false;
            }

            $chunkLength = strlen($chunk);

            if(fwrite($file, $chunk) !== $chunkLength){
                mklog(2, "Failed to write chunk");
                @fclose($file);
                return false;
            }

            $current += $chunkLength;

            if($showProgress){
                if(microtime(true) - $lastStatus > 0.1){
                    echo files::progressTracker($total, $current);
                    $lastStatus = microtime(true);
                }
            }
        }

        if(!self::send($socket, "OK")){
            mklog(2, "Failed to send ok");
            @fclose($file);
            return false;
        }

        if(!fclose($file)){
            mklog(2, "Failed to close file handle");
            return false;
        }

        return true;
    }
    // Actions
    public static function close(Socket $socket):true{
        socket_close($socket);
        return true;
    }
    public static function connect(string $ip, int $port, float|false $timeout, &$socketErrorString):?Socket{
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if($socket === false){
            $socketErrorString = socket_strerror(socket_last_error());
            return null;
        }

        // Set non‑blocking to allow a connection timeout
        socket_set_nonblock($socket);

        if(!@socket_connect($socket, $ip, $port)){
            $error = socket_last_error($socket);
            // In non‑blocking mode, connection may be in progress
            if(!in_array($error, [SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EWOULDBLOCK])){
                // Immediate failure
                $socketErrorString = socket_strerror($error);
                socket_close($socket);
                return null;
            }

            $read = null;
            $write = [$socket];
            $except = [$socket];

            if($timeout === false){
                $timeoutSec = null;
                $timeoutUsec = null;
            }
            else{
                $timeoutSec = (int) $timeout;
                $timeoutUsec = (int) (($timeout - $timeoutSec) * 1_000_000);
            }

            $selectResult = @socket_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if($selectResult === false){
                $socketErrorString = socket_strerror(socket_last_error($socket));
                socket_close($socket);
                return null;
            }

            if($selectResult === 0){
                $socketErrorString = 'Connection timed out';
                socket_close($socket);
                return null;
            }

            // Check the socket error status after select
            $soError = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
            if($soError !== 0){
                $socketErrorString = socket_strerror($soError);
                socket_close($socket);
                return null;
            }

            // Success – set back to blocking mode
        }

        // Connection succeeded immediately or wait success
        socket_set_block($socket);
        $socketErrorString = '';
        @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        return $socket;
    }
    public static function createServer(string $ip, int $port, int|false $timeout, &$socketErrorString):?Socket{
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if($socket === false){
            $socketErrorString = socket_strerror(socket_last_error());
            return null;
        }

        // Allow reusing the address (similar to stream behaviour)
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if(!@socket_bind($socket, $ip, $port)){
            $socketErrorString = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            return null;
        }

        if(!@socket_listen($socket)){
            $socketErrorString = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            return null;
        }

        // Optionally set timeouts on the server socket (for parity with original)
        if($timeout !== false){
            $timeoutSec = (int) $timeout;
            $timeoutUsec = (int) (($timeout - $timeoutSec) * 1_000_000);
            $timeoutArray = ['sec' => $timeoutSec, 'usec' => $timeoutUsec];

            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeoutArray);
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeoutArray);
        }

        $socketErrorCode = 0;
        $socketErrorString = '';
        return $socket;
    }
    public static function acceptConnection(Socket $socketServer, ?float $timeout=null):?Socket{
        if($timeout !== null){
            // Non‑blocking accept with timeout using socket_select
            $read = [$socketServer];
            $write = null;
            $except = null;

            $timeoutSec = (int) $timeout;
            $timeoutUsec = (int) (($timeout - $timeoutSec) * 1_000_000);

            $selectResult = @socket_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if(!$selectResult){
                // Error or timeout
                return null;
            }
        }

        $client = @socket_accept($socketServer);
        @socket_set_option($client, SOL_TCP, TCP_NODELAY, 1);
        return $client === false ? false : $client;
    }
    public static function getLastReceivedName():string{
        return self::$lastReceivedName;
    }
}