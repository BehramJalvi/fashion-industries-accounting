<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('production_orders.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            'processor_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'processing_fee' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'paid_from_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'action' => ['nullable', 'in:draft,post'],
            'inputs' => ['required', 'array', 'min:1', 'max:50'],
            'inputs.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'inputs.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'outputs' => ['nullable', 'array', 'min:1', 'max:50'],
            'outputs.*.product_id' => ['required', 'integer', 'exists:products,id', 'distinct'],
            'outputs.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'outputs.*.allocation_basis' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'output_product_id' => ['required_without:outputs', 'nullable', 'integer', 'exists:products,id'],
            'output_qty' => ['required_without:outputs', 'nullable', 'numeric', 'gt:0', 'max:999999999'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $fee = is_numeric($this->input('processing_fee')) ? (float) $this->input('processing_fee') : 0;
                $paidFromAccountId = $this->input('paid_from_account_id');
                $processorSupplierId = $this->input('processor_supplier_id');

                if ($fee > 0 && ! $paidFromAccountId && ! $processorSupplierId) {
                    $validator->errors()->add('processor_supplier_id', 'Choose the processor to owe, or the cash/bank account used to pay the fee.');
                }

                if ($fee <= 0 && $paidFromAccountId) {
                    $validator->errors()->add('paid_from_account_id', 'A payment account can only be selected when a processing fee is entered.');
                }

                $inputs = $this->input('inputs');
                $outputs = $this->input('outputs');
                $usesLegacyOutputFields = ! is_array($outputs);
                if ($usesLegacyOutputFields && $this->filled('output_product_id')) {
                    $outputs = [['product_id' => $this->input('output_product_id')]];
                }

                if (! is_array($inputs) || ! is_array($outputs)) {
                    return;
                }

                foreach ($inputs as $index => $input) {
                    if (! is_array($input)) {
                        continue;
                    }

                    foreach ($outputs as $outputIndex => $output) {
                        if (is_array($output) && (string) ($input['product_id'] ?? '') === (string) ($output['product_id'] ?? '')) {
                            $field = $usesLegacyOutputFields ? "inputs.{$index}.product_id" : "outputs.{$outputIndex}.product_id";
                            $validator->errors()->add($field, 'Finished products must be different from raw materials.');
                        }
                    }
                }

                if (count($outputs) > 1) {
                    foreach ($outputs as $index => $output) {
                        if (! is_array($output) || ! is_numeric($output['allocation_basis'] ?? null) || (float) $output['allocation_basis'] <= 0) {
                            $validator->errors()->add("outputs.{$index}.allocation_basis", 'Enter a positive estimated production cost for every output when producing multiple products.');
                        }
                    }
                }

                if (! array_is_list($outputs)) {
                    $validator->errors()->add('outputs', 'Production outputs must be a list.');
                }
            },
        ];
    }
}
