<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validator ringan dengan aturan berbasis string.
 *
 * Contoh:
 *   $v = new Validator($request->all(), [
 *       'nama'      => 'required|max:100',
 *       'tgl_lahir' => 'required|date',
 *       'jk'        => 'required|in:L,P,X',
 *   ]);
 *   if ($v->gagal()) { ... $v->errors() ... }
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,string> */
    private const LABEL_DEFAULT = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public function __construct(
        private array $data,
        private array $rules,
        private array $labels = []
    ) {
        $this->jalankan();
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private function jalankan(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            foreach (explode('|', $ruleString) as $rule) {
                if ($rule === '') {
                    continue;
                }

                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                // Aturan selain "required" dilewati bila nilai kosong.
                if ($name !== 'required' && ($value === null || $value === '')) {
                    continue;
                }

                $error = $this->periksa($name, $field, $value, $param);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }
    }

    private function periksa(string $rule, string $field, mixed $value, ?string $param): ?string
    {
        $label = $this->label($field);

        return match ($rule) {
            'required' => ($value === null || $value === '' || $value === [])
                ? $label . ' wajib diisi.' : null,

            'numeric' => !is_numeric(is_string($value) ? str_replace(',', '.', $value) : $value)
                ? $label . ' harus berupa angka.' : null,

            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false
                ? $label . ' harus berupa bilangan bulat.' : null,

            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false
                ? $label . ' bukan alamat email yang sah.' : null,

            'date' => strtotime((string) $value) === false
                ? $label . ' bukan tanggal yang sah.' : null,

            'min' => mb_strlen((string) $value) < (int) $param
                ? $label . ' minimal ' . $param . ' karakter.' : null,

            'max' => mb_strlen((string) $value) > (int) $param
                ? $label . ' maksimal ' . $param . ' karakter.' : null,

            'min_num' => (float) $value < (float) $param
                ? $label . ' minimal ' . $param . '.' : null,

            'max_num' => (float) $value > (float) $param
                ? $label . ' maksimal ' . $param . '.' : null,

            'in' => !in_array((string) $value, explode(',', (string) $param), true)
                ? $label . ' berisi pilihan yang tidak sah.' : null,

            'array' => !is_array($value)
                ? $label . ' harus berupa daftar.' : null,

            'not_empty_array' => (!is_array($value) || $value === [])
                ? $label . ' harus dipilih minimal satu.' : null,

            'unique' => $this->cekUnik($label, $value, (string) $param),

            'exists' => $this->cekAda($label, $value, (string) $param),

            'regex' => preg_match((string) $param, (string) $value) !== 1
                ? $label . ' formatnya tidak sesuai.' : null,

            'confirmed' => ($this->data[$field . '_konfirmasi'] ?? null) !== $value
                ? 'Konfirmasi ' . strtolower($label) . ' tidak cocok.' : null,

            default => null,
        };
    }

    /** Param: tabel,kolom[,id_dikecualikan] */
    private function cekUnik(string $label, mixed $value, string $param): ?string
    {
        $parts  = explode(',', $param);
        $table  = $parts[0];
        $column = $parts[1] ?? 'id';
        $except = $parts[2] ?? null;

        $sql    = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column);
        $params = [$value];

        if ($except !== null && $except !== '') {
            $sql     .= ' AND id <> ?';
            $params[] = $except;
        }

        return (int) Database::scalar($sql, $params) > 0
            ? $label . ' sudah digunakan.' : null;
    }

    /** Param: tabel[,kolom] */
    private function cekAda(string $label, mixed $value, string $param): ?string
    {
        $parts  = explode(',', $param);
        $table  = $parts[0];
        $column = $parts[1] ?? 'id';

        $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column);

        return (int) Database::scalar($sql, [$value]) === 0
            ? $label . ' tidak ditemukan.' : null;
    }

    public function gagal(): bool
    {
        return $this->errors !== [];
    }

    public function lolos(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function pesanPertama(): string
    {
        return (string) (reset($this->errors) ?: '');
    }
}
