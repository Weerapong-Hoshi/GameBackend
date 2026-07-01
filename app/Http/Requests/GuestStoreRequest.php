<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuestStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:12',
                'regex:/^[A-Za-zก-๙0-9_ ]+$/u'
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'กรุณาระบุชื่อผู้เล่น',
            'name.string' => 'ชื่อผู้เล่นต้องเป็นข้อความ',
            'name.min' => 'ชื่อผู้เล่นต้องมีความยาวอย่างน้อย 2 ตัวอักษร',
            'name.max' => 'ชื่อผู้เล่นต้องมีความยาวไม่เกิน 12 ตัวอักษร',
            'name.regex' => 'ชื่อผู้เล่นสามารถมีได้เฉพาะตัวอักษร ตัวเลข _ และเว้นวรรคเท่านั้น',
        ];
    }
}