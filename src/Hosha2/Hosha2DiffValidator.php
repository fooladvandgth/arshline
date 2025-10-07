<?php
namespace Arshline\Hosha2;

class Hosha2DiffValidator
{
    protected ?Hosha2LoggerInterface $logger;
    protected array $errors = [];

    public function __construct(?Hosha2LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function validate(array $diff): bool
    {
        $this->errors = [];
        if ($this->logger) $this->logger->log('diff_validate_start', ['count'=>count($diff)]);
        foreach ($diff as $idx => $op) {
            if (!is_array($op)) { $this->errors[] = "diff[$idx] not array"; continue; }
            if (empty($op['op']) || empty($op['path'])) { $this->errors[] = "diff[$idx] missing op/path"; }
            if (!in_array($op['op'], ['add','remove','replace','move','copy','test'], true)) {
                $this->errors[] = "diff[$idx] invalid op";
            }
        }
        $ok = empty($this->errors);
        if ($this->logger) $this->logger->log('diff_validate_end', ['ok'=>$ok,'errors'=>$this->errors]);
        return $ok;
    }

    public function errors(): array { return $this->errors; }
}
?>