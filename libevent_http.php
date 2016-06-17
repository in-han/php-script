<?php
/***************************************************************************
 * 
 * Copyright (c) 2016 Baidu.com, Inc. All Rights Reserved
 * 
 **************************************************************************/
 
 
 
/**
 * @file libevent.php
 * @date 2016/06/07 10:00:23
 * @brief 
 *  
 **/

// var_dump( get_defined_functions() );

//ini_set("error_log", "/tmp/error.log");
ini_set("display_errors","On");
ini_set("error_reporting", E_ALL);



function my_log( $msg, $error=0 ){
    $dir = dirname( __FILE__ );    
    $fname = $dir . "/my_log%s.log"; 
    if( $error ){
        $fname = sprintf($fname, '.wf');
    }else{
        $fname = sprintf($fname, '');
    }
    $trace = debug_backtrace( );
    $i = 1;
    while( ! isset($trace[ $i ]['file']) || empty( $trace[$i]['file'])  ){
        $i ++;
    }
    $top = $trace[ $i ];

    if( ! isset( $top['file'] ) ){
        var_dump( $trace );
    }

    $all_msg = sprintf("file[%s], line[%s], msg[%s], errno[%s]\n",  $top['file'], $top['line'], $msg, $error);
    file_put_contents( $fname, $all_msg, FILE_APPEND );
} 


function get_id_with_lock(){
    //static $id=0;
    //$id ++;

    //echo "get id                  $id\n";

    $fp = fopen("/tmp/lock.txt", "r+");
    if (flock($fp, LOCK_EX)){
        $str = fread( $fp, 8 );
        $id = intval( $str );
        $id ++;
        echo "$id\n";
        ftruncate($fp, 0);
        fseek($fp, 0);
        fwrite($fp, strval($id));
        fflush($fp);
        flock($fp, LOCK_UN);    // 释放锁定 
    }else{
        echo "can't lock   22222222222222222222\n";
    }
}

/*
$timeout_event = event_new();
event_set($timeout_event, 0, EV_TIMEOUT | EV_PERSIST, function( $fd, $event, $argv ) {
    var_dump( $fd ); 
    var_dump( $event );
    var_dump( $argv );

    echo "function called \n";

    $base = $argv[0];
    $event = $argv[1];
    // event_add($event, 2000000);
}, 
array($base, $timeout_event, 1, 2, 3)
);
event_base_set($timeout_event, $base);          // 设置关联的base, 一次就够了
event_add($timeout_event, 200000000);
 */

$opts = array(
    'socket' => array(
        'backlog'=>100,
    ),
);

$listen_fd = stream_socket_server ('tcp://0.0.0.0:2000', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($opts));
stream_set_blocking( $listen_fd, 0 );

pcntl_fork();
pcntl_fork();
pcntl_fork();
//pcntl_fork();

$base = event_base_new();
$listen_event = event_new();

$g_conns = array();
$g_free_event = array();



function deal_request( $conn_id, $event ){
    $conn = &$GLOBALS['g_conns'][ $conn_id ];

    $wait_close_by_client = false;
    
    $read_len = 0;
    while( $event & EV_READ ){
        $read = fread($conn['conn'], 8);
        if( false !== $read && strlen($read) !== 0 ){
            $conn['read_buf'] .= $read;
            $read_len += strlen($read);
        }else{
            break;
        }
    }

    if( $event & EV_READ && $read_len == 0){
        if( $conn['state'] != 'WAIT_FOR_CLOSE' ){
            my_log( $conn['state'], 256 );
            $conn['state'] = 'CLIENT_FORCE_CLOSE';
            my_log( $conn['state'], 255 );
        }else{
            $conn['state'] = 'CLIENT_NORMAL_CLOSE';
        }
    }

    my_log( $conn['state'] );

    switch( $conn['state'] ){
        case 'INIT':
            if( ( $indx=strpos($conn['read_buf'], "\r\n\r\n") ) === false ){
                break;
            }
            $conn['header_len'] = $indx;
            $conn['body_offset'] = $indx + 4;

            $tmp = explode( "\r\n", substr( $conn['read_buf'], 0, $conn['header_len'] ) ); 
            $headers = array();
            foreach( $tmp as $k => $v ){
                if( $k == 0 ){
                    $method_uri_proto = explode( ' ', $v );
                    $headers['method'] = $method_uri_proto[0];
                    $headers['uri'] = $method_uri_proto[1];
                    $headers['http_v'] = $method_uri_proto[2];
                }else{
                    $hkv = explode( ':', $v );
                    $headers[$hkv[0]] = trim($hkv[1]);
                }
            }
            $conn['headers'] = & $headers;
            if( $headers['method'] == 'GET' ){
                $conn['content_length'] = 0; 
            }else{
                $conn['content_length'] = intval( $headers['Content-Length'] );
            }

            $conn['state'] = 'HAVE_PARSE_HEADER';

            // to continue next stage.
        case 'HAVE_PARSE_HEADER':
            if( strlen($conn['read_buf']) < $conn['body_offset'] + $conn['content_length'] ){
                return;
            }
            $conn['body'] = substr( $conn['read_buf'], $conn['body_offset'], $conn['content_length'] );
            if( $conn['body'] === false ){
                $conn['body'] = '';
            }
            $conn['state'] = 'HAVE_PARSE_BODY';
            unset( $conn['read_buf'] );

        case 'HAVE_PARSE_BODY':
            // before send body
            $resp_header = array(
                'HTTP/1.1 200',
                'Content-Type: text/html',
                'Connection: close',
            );
            $resp_body = $conn['body'];
            $resp_header[] = sprintf("Content-Length: %d", strlen( $resp_body ) );
            $resp_header_txt = implode("\r\n", $resp_header );
            $resp_txt = $resp_header_txt . "\r\n\r\n" . $resp_body;

            $write_len = fwrite( $conn['conn'], $resp_txt, 255  );
            $conn['state'] = 'DOING_RESPONSE';
            $conn['write_buf'] = $resp_txt;
            $conn['write_len'] = $write_len;

            if( $write_len == strlen($conn['write_buf']) ){
                $conn['state'] = 'WAIT_FOR_CLOSE';
            }else{
                break;
            }

        case 'DOING_RESPONSE':  
            //sending response body.
            $fd = $conn['conn'];
            $write_len = fwrite( $fd, substr($conn['write_buf'], $conn['write_len'] ) );
            if( is_int( $write_len ) ){
                $conn['write_len'] += $write_len;
            }
            if( $conn['write_len'] == strlen( $conn['write_buf'] ) ){
                $conn['state'] = 'WAIT_FOR_CLOSE';
            }else{
                break;
            }

        case 'WAIT_FOR_CLOSE':
            // wait client close.     
            //
            if( $wait_close_by_client ){
                break;
            }    
        case 'CLIENT_FORCE_CLOSE':
        case 'CLIENT_NORMAL_CLOSE':
            fclose( $conn['conn'] );
            event_del( $conn['event'] );
            $GLOBALS['g_free_event'][] = $conn['event'];
            unset( $GLOBALS['g_conns'][ $conn_id ] );

            break;
        default:
            var_dump( "unexpected state" );
            var_dump( $conn );
    }
}


event_set($listen_event, $listen_fd, EV_READ | EV_PERSIST, function( $fd, $event, $argv ) {
        global $g_conns;
        global $g_free_event;
        global $base;
        static $conn_id = 0;

        
        //while( true ){
        $conn = @stream_socket_accept( $fd );
        if( false === $conn ){
            return;
        }
        $conn_id += 1;
        stream_set_blocking($conn, 0);  
        my_log( 'conn_id:' .  $conn_id );

        file_put_contents("/tmp/track.txt", "CONN\n", FILE_APPEND);
        if( count($g_free_event) > 0 ){
            $new_ev = array_pop( $g_free_event );
        }else{
            $new_ev = event_new( );
        }
        $conn_event = & $new_ev;

        event_set( $conn_event, $conn, EV_READ | EV_TIMEOUT | EV_PERSIST, function( $fd, $event, $argv ) {
                $conn_id = $argv;
            //    $read_buf = &$GLOBALS['g_conns'][ $conn_id ]['read_buf'];
            //    $conn = &$GLOBALS['g_conns'][ $conn_id ];
                deal_request( $conn_id, $event );
                return;
            },
            $conn_id
        );
        event_base_set( $conn_event, $base );
        event_add( $conn_event, 5000000 );
        
        $g_conns[ $conn_id ] =  array(
            'conn'=>$conn, 
            'fd'=>$conn, 
            'event'=>$conn_event, 
            'read_buf'=>'',
            'write_buf' => '',
            'state'=>'INIT',
        );

    },
    array( $base, $listen_event )
);


event_base_set($listen_event, $base);          // 设置关联的base, 一次就够了
event_add( $listen_event );
$id=1;
while( true ){
    $loop_ret = event_base_loop($base, EVLOOP_ONCE );
    continue;

    $id ++;
    var_dump( $GLOBALS['g_conns'] );
    $t = & $GLOBALS['g_conns'];
    var_dump( $t );
    // event_del( $t[$id-1]['event'] );
    event_free( $t[$id-1]['event'] );
    unset( $t[ $id -1 ] );
    // var_dump( $loop_ret );
}







/* vim: set expandtab ts=4 sw=4 sts=4 tw=100: */
?>
