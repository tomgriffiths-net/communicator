# communicator
This is a package for PHP-CLI that provides communication functions.

# Commands
- **begin**: Starts communicator server if it is insalled.
- **stop**: Sends a stop signal to a local communicator server.

# Settings
- **name**: The name communicator sends with its messages, default is your computers name.
- **password**: The password communicator uses to authenticate messages, has to be set with setPassword function.
- **whitelist**: A list of names that communicator will only accept messages from.
- **whitelistEnabled**: Enables the whitelist feature when set to true, default is false.
- **blacklist**: A list of names that communicator will not accept messages from.

# Functions
- **getName():string|bool**: Returns the set name for communicator on success or false on failure.
- **setPassword(string $password, string $oldPassword):bool**: Sets the password communicator uses, returns true on success or false on failure.
- **getPasswordEncoded():string|bool**: Returns the encoded password for adding to a message, or false on failure.
- **verifyPassword(string $encodedPassword):bool**: Compares given encoded password with local encoded password, return true on match or false on failure.
- **send($stream, string $data):bool**: Send a string of any length to a stream, must be read using receive function, returns true on success or false on failure.
- **receive($stream):string|bool**: Receives a string of any length sent by send function from a stream, returns the string on success or false on failure.
- **sendData($stream, mixed $data, bool $auth=true):bool**: Sends json-encodeable data to a stream, must be read with receiveData function, incorperates name and password, returns true on success or false on failure.
- **receiveData($stream, bool $auth=true):mixed**: Receives data from a stream sent with sendData function, incorperates password, whitelist and blacklist, returns the data on success or false on failure.
- **close($stream):bool**: Closes a stream, returns true on success or false on failure.
- **connect(string $ip, int $port, float|false $timeout, &$socketErrorCode, &$socketErrorString):mixed**: Connects to a socket server, returns the connection stream on success or false on failure.
- **createServer(string $ip, int $port, int|false $timeout, &$socketErrorCode, &$socketErrorString):mixed**: Creates a socket server, returns the server socket on success or false on failure.
- **acceptConnection($socketServer, float|false $timeout):mixed**: Accepts a client connection to a socket server, returns the client stream on success or false on failure.