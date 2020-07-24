<?php
// Copyright (c) 2019
// Author: Jeff Weisberg
// Created: 2019-Jul-24 14:34 (EDT)
// Function: Deduce php data ingestion


class DeduceIngest {
    var $VERSION       = 1.1;
    var $COLLECT_URL   = '//lore.deduce.com/p/collect';
    var $EVENT_URL     = 'https://event.deduce.com/p/event';  // always https
    var $VERHASH;

    var $site;
    var $apikey;
    const DEFAULT_REQUEST_TIMEOUT = 2; // default timeout in seconds
    var $timeout;

    static $lastt;
    static $limit = 0;

    function __construct($site, $apikey, $opts=[]){
        $opts += ['timeout' => self::DEFAULT_REQUEST_TIMEOUT, 'testmode' => false];
        $this->site     = $site;
        $this->apikey   = $apikey;
        $this->timeout  = $opts['timeout'];
        $this->testmode = $opts['testmode'];
        $this->VERHASH  = substr(sha1("php/$this->VERSION"), 0, 16);
    }

    function html($email, $opts=[]){

        $opts += ['testmode' => $this->testmode];
        $data = ['site' => $this->site, 'vers' => $this->VERHASH];

        if( $opts['testmode'] ){
                $data['testmode'] = true;
        }

        // hash email
        if( $this->email_valid($email) ){
            $email = trim($email);
            $data['ehls1'] = sha1(strtolower($email));
            $data['ehus1'] = sha1(strtoupper($email));
            $data['ehlm5'] = md5(strtolower($email));
            $data['ehum5'] = md5(strtoupper($email));
            $data['ehls2'] = hash('sha256', strtolower($email));
            $data['ehus2'] = hash('sha256', strtoupper($email));
        }

        if( array_key_exists('ssl', $opts) ){
                if( $opts['ssl'] ){
                        $opts += ['url' => "https:" . $this->COLLECT_URL ];
                }else{
                        $opts += ['url' => "http:"  . $this->COLLECT_URL ];
                }
        }else{
                $opts += ['url' => $this->COLLECT_URL ];
        }

        $url = $opts['url'];

        $json = json_encode($data, JSON_PRETTY_PRINT);
        $html = <<<EOS
<script type="text/javascript">
var dd_info = $json
</script>
<script type="text/javascript" src="$url" async></script>

EOS;

        return $html;
    }

    // returns error message if there's an error, nothing on success

    function events($evts, $opts=[]){
        if( $this->limited() ){
            return;
        }

        $opts += ['testmode' => $this->testmode, 'url' => $this->EVENT_URL, 'timeout' => $this->timeout, 'backfill' => false];
        $url = $opts['url'];

        $post = [ 'site' => $this->site, 'apikey' => $this->apikey, 'vers' => $this->VERHASH ];
        if( $opts['backfill'] ){
            $post['backfill'] = true;
        }
        if( $opts['testmode'] || $this->testmode ){
            $post['testmode'] = true;
        }

        $post['events'] = array_map( function($e){return $this->fixup_evt($e);}, $evts );

        $json = json_encode($post, JSON_UNESCAPED_SLASHES);
        //echo $json;

        // https json post
        $req = curl_init($url);
        curl_setopt($req, CURLOPT_POST, 1);
        curl_setopt($req, CURLOPT_POSTFIELDS, $json);
        curl_setopt($req, CURLOPT_TIMEOUT, $opts['timeout']);
        curl_setopt($req, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($req, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $res = curl_exec($req);

        $err  = curl_error($req);
        $code = curl_getinfo($req, CURLINFO_HTTP_CODE);
        curl_close($req);

        if( $res ){
            if( $code == 200 ){
                $this->adjust_ok();
                return ;
            }
            $this->adjust_fail();
            return "$code - $res";
        }

        $this->adjust_fail();
        return "error - $err";
    }

    // returns error message if there's an error, nothing on success

    function event($email, $ip, $event, $data=[], $opts=[]){
        if( ! $this->email_valid($email) ){
            return "invalid email";
        }

        $data['email'] = $email;
        $data['ip']    = $ip;
        $data['event'] = $event;

        return $this->events([ $data ], $opts);
    }

    // hash + delete plaintext email, email_prev, cc
    private function fixup_evt($evt){

        if( $this->email_valid($evt['email']) ){
            $email = strtolower(trim($evt['email']));
            $evt['ehls1'] = sha1($email);
            unset($evt['email']);

            if(! array_key_exists('email_provider', $evt) ){
                $evt['email_provider'] = preg_split('/\@/', $email)[1];
            }
        }
        if( $this->email_valid($evt['email_prev']) ){
            $evt['ehls1_prev'] = sha1(strtolower(trim($evt['email_prev'])));
            unset($evt['email_prev']);
        }
        if( $evt['cc'] ){
            $cc = preg_replace('/[^0-9]/', '', $evt['cc']);
            $evt['ccs1'] = sha1($cc);
            unset($evt['cc']);
        }

        return $evt;
    }

    private function email_valid($email){
        return preg_match('/.+\@.+/', $email);
    }

    private function limited(){
        $t = time();
        if( ! self::$lastt ){
            self::$lastt = time();
        }
        $dt = $t - self::$lastt;
        self::$lastt = $t;

        self::$limit *= 0.999 ** $dt;

        return rand() * 100.0 / getrandmax() < self::$limit;

    }
    private function adjust_ok(){
        self::$limit -= 5;
        if(  self::$limit < 0 ){
            self::$limit = 0;
        }
    }
    private function adjust_fail(){
        self::$limit = (9 * self::$limit + 100) / 10;
        if( self::$limit > 100 ){
            self::$limit = 100;
        }
    }

}


