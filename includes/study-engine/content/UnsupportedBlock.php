<?php
namespace EIPSI\StudyEngine\Content;
/** Stable import error identity and payload, separate from its human presentation. */
final class UnsupportedBlock extends \DomainException {
    public const ERROR_CODE = 'UNSUPPORTED_BLOCK';
    private $block;
    private $locations;
    private $description;
    public function __construct(string $block, array $locations = array(), ?string $description = null) {
        $this->block = $block;
        $this->locations = array_values($locations);
        $this->description = $description ?? (self::ERROR_CODE . ': ' . $block);
        parent::__construct($this->description . ($locations ? ' · ' . implode(', ', $locations) . ' · Reemplazá ese bloque en la fuente Gutenberg.' : ''));
    }
    public function errorCode(): string { return self::ERROR_CODE; }
    public function payload(): array { return array('block' => $this->block, 'locations' => $this->locations); }
    public function atLocations(array $locations): self { return new self($this->block, $locations, $this->description); }
}
