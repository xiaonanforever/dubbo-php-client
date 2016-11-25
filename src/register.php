<?php

namespace dubbo;

require_once "invok/cluster.php";
require_once "invok/invoker.php";
use \dubbo\invok\Cluster;
use dubbo\invok\Invoker;


class Register{

    public $config = array(
        'registry_address' => '127.0.0.1:2181',
        'provider_timeout' => 5 //seconds
     );

    public $zookeeper = null;

    protected $ip;

    protected $providersCluster;

    public static $ServiceMap = array();

    protected  $acl = array(
                      array(
                        'perms' => \Zookeeper::PERM_ALL,
                        'scheme' => 'world',
                        'id' => 'anyone' ) );

    public function __construct($options = array())
    {
        $this->config = array_merge($this->config,$options);
        $this->ip = $this->achieveRegisterIp();
        $this->providersCluster = Cluster::getInstance();
        $this->zookeeper= $this->makeZookeeper($this->config['registry_address']);
    }

    private function makeZookeeper($registry_address) {
                return new \Zookeeper ($registry_address);
    }

    public function subscribe($invokDesc){
           $desc = $invokDesc->toString();
           $serviceName = $invokDesc->getService();

           $path = $this->getSubscribePath($serviceName);
           $children = $this->zookeeper->getChildren($path);
           if(count($children) > 0){
                foreach ($children as $key => $provider) {
                    $provider = urldecode($provider);
                    $this->methodChangeHandler($invokDesc, $provider);
                }
               $this->configurators();
            }
    }

    /**
     * @param $invokDesc
     * @param $invoker
     * @return bool
     * Register consumer information node to the zookeeper.
     */
    public function register($invokDesc, $invoker){
        $desc = $invokDesc->toString();
        if(!array_key_exists($desc,self::$ServiceMap)){
            self::$ServiceMap[$desc] = $invoker;
        }
        $this->subscribe($invokDesc);
        $providerHost = $this->providersCluster->getProvider($invokDesc);
        $invoker->setHost(Invoker::genDubboUrl($providerHost,$invokDesc));
        $registerNode = $this->makeRegistryNode($invokDesc->getService());
        try {
            $parts = explode('/', $registerNode);
            $parts = array_filter($parts);
            $subpath = '';
            while (count($parts) > 1) {
                $subpath .= '/' . array_shift($parts);
                if (!$this->zookeeper->exists($subpath)) {
                    $this->zookeeper->create($subpath,'',$this->acl, null);
                }
            }
            if(!$this->zookeeper->exists($registerNode)) {
                $this->zookeeper->create($registerNode, '', $this->acl, null);
            }
        }catch (ZookeeperNoNodeException $ze){
            error_log("This zookeeper node does not exsit.Please check the zookeeper node information.");
        }
        return true;
    }


    public function methodChangeHandler($invokerDesc, $provider){
        $schemeInfo = parse_url($provider);
        $providerConfig = array();
        parse_str($schemeInfo['query'],$providerConfig);
        if($invokerDesc->isMatch($providerConfig['group'],$providerConfig['version']))
        {
            $this->providersCluster->addProvider($invokerDesc,'http://'.$schemeInfo['host'].':'.$schemeInfo['port'],$schemeInfo['scheme']);
        }
    }


    public function getInvoker($invokerDesc){
        $desc = $invokerDesc->toString();
        return self::$ServiceMap[$desc];
    }



    public function configurators(){
        return true;
    }



    protected function getSubscribePath($serviceName){
        return '/dubbo/' .$serviceName.'/providers';
    }

    protected function getRegistryAddress() {
            return $this->config['registry_address'];
    }


    /**
     * @param $serviceName
     * @param array $application
     * @return string
     * Make a dubbo consumer address for this node which want register itself under the dubbo service
     *
     */
    private function makeRegistryNode($serviceName, $application = array()){
        $params = http_build_query($application);
        $url = '/dubbo/'.$serviceName.'/consumers/'.urlencode('consumer://'.$this->ip.'/'.$serviceName.'?').$params;
        return $url;
}


    /**
     * @param $serviceName
     * @param array $application
     * @return string
     */
    private function makeRegistryPath($serviceName, $application = array()){
        $params = http_build_query($application);
        $path = '/dubbo/'.$serviceName.'/consumers';
        return $path;
}


    protected function getConfiguratorsPath($serviceName){
            return '/dubbo/'.$serviceName.'/configurators';
    }

    protected function  getProviderTimeout(){
            return $this->config['provider_timeout'] * 1000;
    }


    public function zkinfo($invokerDesc){
        echo $this->getRegistryPath($invokerDesc->getService());
        var_dump($this->providersCluster->getProviders());
        var_dump($this->providersCluster);
    }

    /**
     * @return stirn
     * Get the consumer server local ip. If we can't get the ip from the environments,
     * we will get from the command.
     */
    private function achieveRegisterIp(){
    try {
        $registerIp = gethostbyaddr($_SERVER['SERVER_ADDR']);
        if (empty($registerIp)) {
            if (substr(strtolower(PHP_OS), 0, 3) != 'win') {
                $ss = exec('/sbin/ifconfig | sed -n \'s/^ *.*addr:\\([0-9.]\\{7,\\}\\) .*$/\\1/p\'', $arr);
                $registerIp = $arr[0];
            }else{
               /** @TODO
                *  @ implement the windows ip
                **/
            }
        }
    }catch (\Exception $e){
        error_log("We can't get your local ip address.\n");
        error_log($e->getMessage()."\n");
    }
    return $registerIp;
}


}


?>