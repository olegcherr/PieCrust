<?php

/**
 * The class that does most of the work for the web-server.
 */
class StupidHttp_Driver
{
    const REQUEST_DATE_FORMAT = "Y/m/d H:i:s";
    const REQUEST_LOG_FORMAT = "[%date%] %client_ip% --> %method% %path% --> %status% %status_name% [%time%ms]";

    protected $options;
    protected $connection;

    // Driver Properties {{{
    protected $server;
    /**
     * Gets the server this driver is working for.
     */
    public function getServer()
    {
        return $this->server;
    }

    protected $log;
    /**
     * Gets the logger.
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * Sets the logger.
     */
    public function setLog($log)
    {
        $this->log = $log;
    }

    protected $vfs;
    /**
     * Gets the virtual file system.
     */
    public function getVfs()
    {
        return $this->vfs;
    }

    protected $handler;
    /**
     * Gets the network handler.
     */
    public function getNetworkHandler()
    {
        return $this->handler;
    }

    protected $requestHandlers;
    /**
     * Gets the request handlers.
     */
    public function getRequestHandlers()
    {
        return $this->requestHandlers;
    }

    /**
     * Adds a request handler.
     */
    public function addRequestHandler($method, $handler)
    {
        $method = strtoupper($method);
        if (!isset($this->requestHandlers[$method]))
        {
            $this->requestHandlers[$method] = array();
        }
        $this->requestHandlers[$method][] = $handler;
    }

    protected $preprocessor;
    /**
     * Gets the request pre-processor.
     */
    public function getPreprocessor()
    {
        return $this->preprocessor;
    }

    /**
     * Sets the request pre-processor.
     */
    public function setPreprocessor($preprocessor)
    {
        if (!is_callable($preprocessor)) 
            throw new InvalidArgumentException('The preprocessor needs to be a callable object.');
        $this->preprocessor = $preprocessor;
    }
    // }}}

    // Construction and Main Methods {{{
    /**
     * Builds a new instance of StupidHttp_Driver.
     */
    public function __construct($server, $vfs, $handler, $log)
    {
        $this->server = $server;
        $this->vfs = $vfs;
        $this->handler = $handler;

        if ($log == null)
            $log = new StupidHttp_Log();
        $this->log = $log;

        $this->requestHandlers = array();
    }

    /**
     * Initializes the driver.
     */
    public function register()
    {
        $this->handler->register();
    }

    /**
     * Shuts down the driver.
     */
    public function unregister()
    {
        $this->handler->unregister();
    }

    /**
     * Runs the driver's main loop.
     */
    public function run($options)
    {
        $this->options = $options;
        $this->connection = false;
        $this->handler->setLog($this->log);
        do
        {
            $this->runOnce();
        }
        while (true);
    }
    // }}}

    // Secondary Methods {{{
    /**
     * Runs one request.
     */
    public function runOnce()
    {
        // Establish a new connection if needed.
        if ($this->connection === false)
        {
            $this->log->debug('Establishing connection...');
            $this->connection = $this->handler->connect($this->options);
        }

        // Receive a new request.
        $requestInfo = $this->readRequest($this->connection);
        if (!$requestInfo['error'])
        {
            $response = $this->processRequest($requestInfo);
            $this->sendResponse($response, $requestInfo);
        }

        // Logging...
        $this->logRequest($requestInfo);
            
        // Close the connection if it's OK to do so, or if the request was invalid.
        if ($requestInfo['close_socket'] or $requestInfo['error'])
        {
            $this->log->debug("Closing connection.");
            $this->handler->disconnect($this->connection);
            $this->connection = false;
        }
    }

    protected function readRequest($connection)
    {
        $rawRequest = false;
        $rawBody = false;
        $error = false;
        $profiling = array();
        try
        {
            // Start profiling.
            $profiling['receive.start'] = microtime(true);

            // Read the request header.
            $rawRequestStr = $this->handler->readUntil($connection, "\r\n\r\n");
            $rawRequest = explode("\r\n", $rawRequestStr);

            // Figure out if there's a body.
            $contentLength = -1;
            foreach ($rawRequest as $line)
            {
                $m = array();
                if (preg_match('/^Content\-Length\:\s*(\d+)\s*$/', $line, $m))
                {
                    $contentLength = (int)$m[1];
                }
            }
            if ($contentLength > 0)
            {
                // Read the body chunk.
                $rawBody = $this->handler->read($connection, $contentLength);
            }
        }
        catch (StupidHttp_TimedOutException $e)
        {
            // Kept-alive connection probably timed out. Just close it.
            if ($rawRequest === false)
            {
                $this->log->debug("Timed out... ending conversation.");
            }
            else
            {
                $this->log->error("Timed out while receiving request.");
            }
            $error = true;
        }
        catch (StupidHttp_NetworkException $e)
        {
            // Actual network read error.
            $this->log->error("Error reading request from connection: " . $e->getMessage());
            $error = true;
        }

        // End profiling.
        $profiling['receive.end'] = microtime(true);

        // Return the request info.
        return array(
            'error' => $error,
            'profiling' => $profiling,
            'headers' => $rawRequest,
            'body' => $rawBody,
            'request' => null,
            'response' => null,
            'close_socket' => true
        );
    }

    protected function processRequest(&$requestInfo)
    {
        // Create the request object.
        $requestInfo['profiling']['process.start'] = microtime(true);
        $request = new StupidHttp_WebRequest(
            $this->buildServerInfo(),
            $requestInfo['headers'],
            $requestInfo['body']
        );
        $requestInfo['request'] = $request;

        // Process the request, get the response.
        try
        {
            $processor = new StupidHttp_ResponseBuilder(
                $this->vfs, 
                $this->preprocessor,
                $this->requestHandlers,
                $this->log
            );
            $response = $processor->run($this->options, $request);
        }
        catch (StupidHttp_WebException $e)
        {
            $this->log->error('Error processing request:');
            $this->log->error($e->getCode() . ': ' . $e->getMessage());
            if ($e->getCode() != 0)
            {
                $response = new StupidHttp_WebResponse($e->getCode());
            }
            else
            {
                $response = new StupidHttp_WebResponse(500);
            }
        }
        catch (Exception $e)
        {
            $this->log->error('Error processing request:');
            $this->log->error($e->getCode() . ': ' . $e->getMessage());
            $response = new StupidHttp_WebResponse(500);
        }
        $requestInfo['response'] = $response;
        $requestInfo['profiling']['process.end'] = microtime(true);
                
        // Figure out whether to close the connection with the client.
        $closeSocket = true;
        if ($this->options['keep_alive'])
        {
            switch ($request->getVersion())
            {
            case 'HTTP/1.0':
            default:
                // Always close, unless asked to keep alive.
                $closeSocket = ($request->getHeader('Connection') != 'keep-alive');
                break;
            case 'HTTP/1.1':
                // Always keep alive, unless asked to close.
                $closeSocket = ($request->getHeader('Connection') == 'close');
                break;
            }
        }
        else
        {
            $closeSocket = true;
        }
        $requestInfo['close_socket'] = $closeSocket;

        // Adjust the headers.
        if ($closeSocket)
            $response->setHeader('Connection', 'close');
        else
            $response->setHeader('Connection', 'keep-alive');

        if ($response->getHeader('Content-Length') == null)
        {
            if ($response->getBody() != null) 
                $response->setHeader('Content-Length', strlen($response->getBody()));
            else
                $response->setHeader('Content-Length', 0);
        }

        return $response;
    }

    protected function sendResponse($response, &$requestInfo)
    {
        $requestInfo['profiling']['send.start'] = microtime(true);
        try
        {
            $responseInfo = array();
            $responseStr = $this->buildRawResponse($response, $responseInfo);
            $transmitted = $this->handler->write($this->connection, $responseStr);
            $responseInfo['transmitted'] = $transmitted;
            $this->checkTransmittedResponse($response, $responseInfo);
        }
        catch (Exception $e)
        {
            $this->log->error('Error sending response:');
            $this->log->error($e->getCode() . ': ' . $e->getMessage());
        }
        $requestInfo['profiling']['send.end'] = microtime(true);
    }

    protected function buildServerInfo()
    {
        return array(
            'SERVER_NAME' => $this->handler->getAddress(),
            'SERVER_PORT' => $this->handler->getPort()
        );
    }

    protected function buildRawResponse($response, &$responseInfo)
    {
        $statusName = StupidHttp_WebServer::getHttpStatusHeader($response->getStatus());

        $responseStr = "HTTP/1.1 " . $statusName . PHP_EOL;
        $responseStr .= "Server: PieCrust Chef Server".PHP_EOL;
        $responseStr .= "Date: " . date("D, d M Y H:i:s T") . PHP_EOL;
        foreach ($response->getFormattedHeaders() as $header)
        {
            $responseStr .= $header . PHP_EOL;
        }
        $responseStr .= PHP_EOL;
        $responseInfo['header_length'] = strlen($responseStr);

        if ($response->getBody() != null)
        {
            $responseStr .= $response->getBody();
        }
        return $responseStr;
    }

    protected function checkTransmittedResponse($response, $responseInfo)
    {
        $transmitted = $responseInfo['transmitted'];
        $headerLength = $responseInfo['header_length'];

        $declaredLength = intval($response->getHeader('Content-Length'));
        $this->log->debug('Transmitted ' . $transmitted . ' bytes.');
        if (($declaredLength + $headerLength) != $transmitted)
        {
            $this->log->error("Discrepancy of " . ($transmitted - $declaredLength - $headerLength) . " bytes between transmitted byte count and declared byte count.");
        }
        if ($declaredLength != strlen($response->getBody()))
        {
            $this->log->error("Declared body length was " . $declaredLength . " but should have been " . strlen($response->getBody()));
        }
    }

    protected function logRequest($requestInfo)
    {
        // Get profiling info.
        $profiling = $requestInfo['profiling'];
        $receiveTime = $processTime = $sendTime = 0;
        if (isset($profiling['receive.start']) and isset($profiling['receive.end']))
        {
            $receiveTime = ($profiling['receive.end'] - $profiling['receive.start']) * 1000.0;
        }
        if (isset($profiling['process.start']) and isset($profiling['process.end']))
        {
            $processTime = ($profiling['process.end'] - $profiling['process.start']) * 1000.0;
        }
        if (isset($profiling['send.start']) and isset($profiling['send.end']))
        {
            $sendTime = ($profiling['send.end'] - $profiling['send.start']) * 1000.0;
        }
        $totalTime = ceil($receiveTime + $processTime + $sendTime);

        // Do the logging.
        $request = $requestInfo['request'];
        $response = $requestInfo['response'];
        if ($request and $response)
        {
            $clientInfo = $this->handler->getClientInfo($this->connection);
            $statusName = StupidHttp_WebServer::getHttpStatusHeader($response->getStatus());
            $replacements = array(
                '%date%' => date(self::REQUEST_DATE_FORMAT),
                '%client_ip%' => $clientInfo['address'],
                '%client_port%' => $clientInfo['port'],
                '%method%' => $request->getMethod(),
                '%uri%' => $request->getUri(),
                '%path%' => $request->getUriPath(),
                '%status%' => $response->getStatus(),
                '%status_name%' => $statusName,
                '%time%' => $totalTime
            );
            $this->log->info(
                str_replace(
                    array_keys($replacements),
                    array_values($replacements),
                    self::REQUEST_LOG_FORMAT
                )
            );
        }
    }
    // }}}
}

