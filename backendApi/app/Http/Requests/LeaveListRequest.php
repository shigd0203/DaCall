<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveListRequest extends FormRequest
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
    // 查詢請假紀錄格式驗證
    public function rules(): array
    {
        return [
            //
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'leave_type' => ['nullable', 'exists:leave_types,id'],  // 驗證 leave_type 是 leave_types 表中的有效 id
            'attachment' => 'nullable|exists:files,id',  // 驗證 attachment 是 files 表中的有效 id
            'status' => 'nullable|integer|in:0,1,2,3,4,5',
        ];
    }
}
