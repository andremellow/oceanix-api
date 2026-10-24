<?php
new class extends \Oceanix\Platform\Livewire\People
{
    public string $department_id = '';
    public string $job_function_id = '';

    protected function filterNames(): array
    {
        return [...parent::filterNames(), 'department_id', 'job_function_id'];
    }

    protected function filterRules(): array
    {
        return [...parent::filterRules(), 'department_id' => 'nullable|integer', 'job_function_id' => 'nullable|integer'];
    }
};
?>
@include('oceanix-platform::people')
