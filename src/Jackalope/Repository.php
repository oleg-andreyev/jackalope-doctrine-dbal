<?php

namespace Jackalope;

use PHPCR\CredentialsInterface;

/**
 * The jackalope implementation of the repository.
 *
 * {@inheritDoc}
 */
class Repository implements \PHPCR\RepositoryInterface
{
    /**
     * flag to call stream_wrapper_register only once
     */
    protected static $binaryStreamWrapperRegistered;

    /**
     * The factory to instantiate objects
     *
     * @var object
     */
    protected $factory;

    /**
     * The transport to use
     * @var \Jackalope\TransportInterface
     */
    protected $transport;

    /**
     * List of supported options
     * @var arrray
     */
    protected $options = array(
        // this is OPTION_TRANSACTIONS_SUPPORTED
        'transactions' => true,
        // TODO: we could expose this as a custom descriptor
        'stream_wrapper' => true,
    );

    /**
     * Cached array of repository descriptors. Each is either a string or an
     * array of strings.
     *
     * @var array
     */
    protected $descriptors;

    /**
     * Create repository with the option to overwrite the factory and bound to
     * a transport.
     *
     * Use \Jackalope\RepositoryFactoryDoctrineDBAL or
     * \Jackalope\RepositoryFactoryJackrabbit to instantiate this class.
     *
     * @param object $factory  an object factory implementing "get" as
     *      described in \Jackalope\Factory. If this is null, the
     *      \Jackalope\Factory is instantiated. Note that the repository is the
     *      only class accepting null as factory.
     * @param $transport transport implementation
     * @param array $options defines optional features to enable/disable (see
     *      $options property)
     */
    public function __construct($factory = null, TransportInterface $transport, array $options = null)
    {
        $this->factory = is_null($factory) ? new Factory : $factory;
        $this->transport = $transport;
        $this->options = array_merge($this->options, (array)$options);
        $this->options['transactions'] = $this->options['transactions'] && $transport instanceof TransactionalTransportInterface;
        // register a stream wrapper to lazily load binary property values
        if (null === self::$binaryStreamWrapperRegistered) {
            self::$binaryStreamWrapperRegistered = $this->options['stream_wrapper'];
            if (self::$binaryStreamWrapperRegistered) {
                stream_wrapper_register('jackalope', 'Jackalope\\BinaryStreamWrapper');
            }
        }
    }

    // inherit all doc
    /**
     * @api
     */
    public function login(CredentialsInterface $credentials = null, $workspaceName = null)
    {
        if ($workspaceName == null) {
            //TODO: can default workspace have other name?
            $workspaceName = 'default';
        }
        if (! $this->transport->login($credentials, $workspaceName)) {
            throw new \PHPCR\RepositoryException('transport failed to login without telling why');
        }

        $session = $this->factory->get('Session', array($this, $workspaceName, $credentials, $this->transport));
        if ($this->options['transactions']) {
            $utx = $this->factory->get('Transaction\\UserTransaction', array($this->transport, $session));
            $session->getWorkspace()->setTransactionManager($utx);
        }

        return $session;
    }

    // inherit all doc
    /**
     * @api
     */
    public function getDescriptorKeys()
    {
        if (null === $this->descriptors) {
            $this->loadDescriptors();
        }
        return array_keys($this->descriptors);
    }

    // inherit all doc
    /**
     * @api
     */
    public function isStandardDescriptor($key)
    {
        $ref = new \ReflectionClass('\PHPCR\RepositoryInterface');
        $consts = $ref->getConstants();
        return in_array($key, $consts);
    }

    // inherit all doc
    /**
     * @api
     */
    public function getDescriptor($key)
    {
        // handle some of the keys locally
        switch($key) {
            case self::OPTION_TRANSACTIONS_SUPPORTED:
                return $this->options['transactions'];
            // TODO: return false for everything we know is not implemented in jackalope
        }

        // handle the rest by the transport to allow non-feature complete transports
        // or use interface per capability?
        if (null === $this->descriptors) {
            $this->descriptors = $this->transport->getRepositoryDescriptors();
        }
        return (isset($this->descriptors[$key])) ?  $this->descriptors[$key] : null;
        //TODO: is this the proper behaviour? Or what should happen on inexisting key?
    }

    /**
     * Load the descriptors from the transport and cache them
     */
    protected function loadDescriptors()
    {
        $this->descriptors = $this->transport->getRepositoryDescriptors();
    }
}
