<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Login Request
 *
 * Accepts email, username (user_id), or a generic login field.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => 'required_without_all:email,username,user_id|string',
            'email' => 'required_without_all:login,username,user_id|string',
            'username' => 'required_without_all:login,email,user_id|string',
            'user_id' => 'required_without_all:login,email,username|string',
            'password' => 'required|string|min:6',
        ];
    }

    /**
     * Resolve the login identifier (email or username / user_id).
     */
    public function identifier(): string
    {
        return (string) (
            $this->input('login')
            ?? $this->input('username')
            ?? $this->input('user_id')
            ?? $this->input('email')
        );
    }

    public function messages(): array
    {
        return [
            'login.required_without_all' => 'Email or username is required',
            'email.required_without_all' => 'Email or username is required',
            'username.required_without_all' => 'Email or username is required',
            'user_id.required_without_all' => 'Email or username is required',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 6 characters',
        ];
    }
}
