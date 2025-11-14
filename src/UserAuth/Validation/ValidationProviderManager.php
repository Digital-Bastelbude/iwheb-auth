<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Validation;

/**
 * ValidationProviderManager
 * 
 * Manages validation providers and their registration.
 */
class ValidationProviderManager {
    /**
     * @var array<string, ValidationProviderInterface> Registered providers
     */
    private array $providers = [];
    
    /**
     * @var string Default provider name
     */
    private string $defaultProvider = 'email';
    
    /**
     * Register a validation provider
     * 
     * @param ValidationProviderInterface $provider The provider to register
     * @return void
     */
    public function register(ValidationProviderInterface $provider): void {
        $this->providers[$provider->getName()] = $provider;
    }
    
    /**
     * Get a validation provider by name
     * 
     * @param string|null $name The provider name, or null for default
     * @return ValidationProviderInterface|null The provider or null if not found
     */
    public function getProvider(?string $name = null): ?ValidationProviderInterface {
        // Use default if no name provided
        if ($name === null || $name === '') {
            $name = $this->defaultProvider;
        }
        
        return $this->providers[$name] ?? null;
    }
    
    /**
     * Get the default provider
     * 
     * @return ValidationProviderInterface The default provider
     * @throws \RuntimeException if default provider is not registered
     */
    public function getDefaultProvider(): ValidationProviderInterface {
        $provider = $this->getProvider($this->defaultProvider);
        
        if ($provider === null) {
            throw new \RuntimeException("Default validation provider '{$this->defaultProvider}' is not registered");
        }
        
        return $provider;
    }
    
    /**
     * Set the default provider name
     * 
     * @param string $name The provider name to use as default
     * @return void
     */
    public function setDefaultProvider(string $name): void {
        $this->defaultProvider = $name;
    }
    
    /**
     * Check if a provider is registered
     * 
     * @param string $name The provider name
     * @return bool True if registered
     */
    public function hasProvider(string $name): bool {
        return isset($this->providers[$name]);
    }
    
    /**
     * Get all registered provider names
     * 
     * @return array<string> List of provider names
     */
    public function getProviderNames(): array {
        return array_keys($this->providers);
    }
}
