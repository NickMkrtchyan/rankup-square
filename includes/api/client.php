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

    protected function headers($content_type = 'application/json'){
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Square-Version'=> $this->version,
        ];
        if ($content_type) {
            $headers['Content-Type'] = $content_type;
        }
        return $headers;
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
        if ($code<200||$code>=300) Helpers::log(['bad_response' => ['url' => $res['http_response']->get_response_object()->url, 'code' => $code, 'body' => $body]], 'error');
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

    // Images
    public function upload_image($attachment_id, $product_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return [0, null, 'File not found for attachment ID: ' . $attachment_id];
        }

        $url = rtrim($this->base, '/') . '/v2/catalog/images';
        $boundary = '--------------------------' . microtime(true);

        $json_object = [
            'idempotency_key' => wp_generate_uuid4(),
            'image' => [
                'type' => 'IMAGE',
                'id' => '#IMG-' . $product_id . '-' . $attachment_id,
                'image_data' => [
                    'caption' => get_the_title($attachment_id),
                ]
            ]
        ];

        $body = "--" . $boundary . "\r\n";
        $body .= "Content-Disposition: form-data; name=\"request\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= wp_json_encode($json_object) . "\r\n";
        
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"" . basename($file_path) . "\"\r\n";
        $body .= "Content-Type: " . mime_content_type($file_path) . "\r\n\r\n";
        $body .= file_get_contents($file_path) . "\r\n";
        
        $body .= "--" . $boundary . "--\r\n";

        $args = [
            'headers' => $this->headers('multipart/form-data; boundary=' . $boundary),
            'body'    => $body,
            'timeout' => 60,
        ];
        
        $res = wp_remote_post($url, $args);

        return $this->handle($res);
    }
}