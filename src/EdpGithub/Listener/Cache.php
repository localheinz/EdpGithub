<?php

namespace EdpGithub\Listener;

use Zend\Cache\Storage;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\Http;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\ServiceManagerAwareInterface;

class Cache implements ListenerAggregateInterface, ServiceManagerAwareInterface
{
    protected $listeners = array();

    protected $serviceManager;

    protected $cacheKey;

    protected $cache;

    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach('pre.send', array($this, 'preSend'), -1000);
        $this->listeners[] = $events->attach('post.send', array($this, 'postSend'));
    }

    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    public function preSend(Event $e)
    {
        $request = $e->getTarget();

        $cache = $this->getCache();

        $this->cacheKey = md5($request);

        $cache->getItem($this->cacheKey, $success);
        if ($success) {
            $tags = $cache->getTags($this->cacheKey);
            if (isset($tags[0])) {
                $request->getHeaders()->addHeaders(array(
                    'If-None-Match' => $tags[0],
                ));
            }
        }
    }

    public function postSend(Event $e)
    {
        $response = $e->getTarget();

        $statusCode =  $response->getStatusCode();

        $cache = $this->getCache();

        if ($statusCode == 304) {
            $response =  $cache->getItem($this->cacheKey);
        } else {
            $cache->setItem($this->cacheKey, $response);
            /* @var Http\Response $response */
            $headers = $response->getHeaders();
            if ($headers->get('Etag')) {
                $etag = $headers->get('Etag')->getFieldValue();
                $tags = array(
                    'etag' => $etag,
                );
                $cache->setTags($this->cacheKey, $tags);
            }
        }
        $e->stopPropagation(true);

        return $response;
    }

    /**
     * @return Storage\StorageInterface|Storage\TaggableInterface
     */
    public function getCache()
    {
        if ($this->cache === null) {
            /* @var $cache Storage\StorageInterface */
            $cache = $this->getServiceManager()->get('edpgithub.cache');

            $this->cache = $cache;
        }

        return $this->cache;
    }

    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    /**
     * @return ServiceManager
     */
    public function getServiceManager()
    {
        return $this->serviceManager;
    }
}
