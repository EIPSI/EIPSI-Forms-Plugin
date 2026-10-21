<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Engine\RandomStream;

/** Dedicated server keyring, supplied outside DB/HTTP. Standard OpenSSL AES-GCM. */
class SeedVault {
    private $keys;
    private $current;
    public function __construct(array $keys,string $current) {
        Check::id($current,'key_id');
        foreach($keys as $id=>$key){Check::id($id,'key_id');if(!is_string($key)||strlen($key)!==32)throw new \InvalidArgumentException('KEY_MUST_HAVE_32_BYTES');}
        if(!isset($keys[$current]))throw new IntegrityFailure('SEED_KEY_UNAVAILABLE');
        if(!in_array('aes-256-gcm',openssl_get_cipher_methods(),true))throw new IntegrityFailure('SEED_CRYPTO_UNAVAILABLE');
        $this->keys=$keys;$this->current=$current;
    }
    protected function entropy(): string { return random_bytes(32); }
    public function create(string $executionId): array {
        $seed=$this->entropy();if(strlen($seed)!==32)throw new IntegrityFailure('INVALID_SEED_ENTROPY');
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($seed,'aes-256-gcm',$this->keys[$this->current],OPENSSL_RAW_DATA,$nonce,$tag,$executionId,16);
        if($cipher===false||strlen($tag)!==16)throw new IntegrityFailure('SEED_ENCRYPT_FAILED');
        return array('key_id'=>$this->current,'ciphertext'=>base64_encode($nonce.$tag.$cipher),'seed_hex'=>bin2hex($seed));
    }
    public function open(string $executionId,string $keyId,string $encoded): string {
        if(!isset($this->keys[$keyId]))throw new IntegrityFailure('SEED_KEY_UNAVAILABLE');
        $raw=base64_decode($encoded,true);if($raw===false||strlen($raw)!==60)throw new IntegrityFailure('SEED_CIPHERTEXT_INVALID');
        $seed=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->keys[$keyId],OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$executionId);
        if($seed===false||strlen($seed)!==32)throw new IntegrityFailure('SEED_AUTHENTICATION_FAILED');$hex=bin2hex($seed);RandomStream::validateSeed($hex);return $hex;
    }
}
