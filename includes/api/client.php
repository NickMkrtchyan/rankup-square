<?php
namespace RSWI\API; use RSWI\Helpers; if ( ! defined('ABSPATH') ) exit;

class Client {
    const OPT_TOKEN   = 'rswi_square_token';
    const OPT_ENV     = 'rswi_square_env';      // production|sandbox
    const OPT_VERSION = 'rswi_square_version'; // e.g. 2025-06-20
    const OPT_LOC     = 'rswi_square_location';

    protected $token; protected $env; protected $version; protected $base; protected $location;

    public function __construct(){
        $this->token   = trim((string) get_option(self::OPT_TOKEN, ''));
        $this->env     = get_option(self::OPT_ENV, 'production');
        $this->version = get_option(self::OPT_VERSION, '2025-06-20');
        $this->base    = ($this->env==='sandbox')?'https://connect.squareupsandbox.com':'https://connect.squareup.com';
        $this->location= get_option(self::OPT_LOC, '');
    }
    public function ready(){ return !empty($this->token); }
    public function get_location(){ return (string) $this->location; }

    protected function headers(){
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Square-Version'=> $this->version,
            'Content-Type'  => 'application/json',
        ];
    }
    public function get($path, $query=[]){
        $url = rtrim($this->base,'/').'/'.ltrim($path,'/');
        if (!empty($query)) $url = add_query_arg($query, $url);
        $res = wp_remote_get($url, ['headers'=>$this->headers(),'timeout'=>45]);
        return $this->handle($res);
    }
    public function post($path, $body=[]){
        $url = rtrim($this->base,'/').'/'.ltrim($path,'/');
        $res = wp_remote_post($url, ['headers'=>$this->headers(),'timeout'=>45,'body'=>wp_json_encode($body)]);
        return $this->handle($res);
    }
    public function put($path, $body=[]){
        $url = rtrim($this->base,'/').'/'.ltrim($path,'/');
        $res = wp_remote_request($url, ['method'=>'PUT','headers'=>$this->headers(),'timeout'=>45,'body'=>wp_json_encode($body)]);
        return $this->handle($res);
    }
    protected function handle($res){
        if (is_wp_error($res)) { Helpers::log('HTTP error: '.$res->get_error_message(),'error'); return [0,null,$res->get_error_message()]; }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code<200||$code>=300) Helpers::log(['bad'=>$body,'code'=>$code],'error');
        return [$code,$body,null];
    }

    // Convenience calls
    public function list_variations($cursor=null){
        $q=['types'=>'ITEM_VARIATION']; if($cursor) $q['cursor']=$cursor; return $this->get('/v2/catalog/list',$q);
    }
    public function list_items($cursor=null){
        $q=['types'=>'ITEM']; if($cursor) $q['cursor']=$cursor; return $this->get('/v2/catalog/list',$q);
    }
    public function upsert_catalog($objects, $idempotency=null){
        $body=['idempotency_key'=>$idempotency?:wp_generate_uuid4(),'batches'=>[['objects'=>$objects]]];
        return $this->post('/v2/catalog/batch-upsert',$body);
    }
    public function retrieve_object($id, $include_related=false){
        $q = $include_related ? ['include_related_objects'=>'true'] : [];
        return $this->get('/v2/catalog/object/'.$id, $q);
    }
    public function search_catalog($body){ return $this->post('/v2/catalog/search',$body); }

    // Orders
    public function create_order($order){ return $this->post('/v2/orders', ['order'=>$order, 'idempotency_key'=>wp_generate_uuid4()]); }
}
