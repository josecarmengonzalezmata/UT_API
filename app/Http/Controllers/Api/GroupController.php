<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GroupController extends Controller
{
    private function storagePath(): string
    {
        return 'groups.json';
    }

    private function load(): array
    {
        $path = $this->storagePath();
        if (!Storage::exists($path)) return [];
        $raw = Storage::get($path);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function save(array $groups): void
    {
        Storage::put($this->storagePath(), json_encode(array_values($groups), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->load()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'careerCode' => ['required', 'string', 'max:32'],
            'plan' => ['required', 'in:nuevo-modelo,plan-normal'],
            'cuatrimestre' => ['required', 'integer'],
            'groupNumber' => ['required', 'integer'],
        ]);

        $groups = $this->load();
        $id = (int) round(microtime(true) * 1000);
        $name = strtoupper($data['careerCode']) . $data['cuatrimestre'] . '-' . $data['groupNumber'];
        $g = [
            'id' => $id,
            'careerCode' => strtoupper($data['careerCode']),
            'plan' => $data['plan'],
            'cuatrimestre' => (int) $data['cuatrimestre'],
            'groupNumber' => (int) $data['groupNumber'],
            'name' => $name,
        ];

        array_unshift($groups, $g);
        $this->save($groups);

        return response()->json(['data' => $g], 201);
    }

    public function show($id): JsonResponse
    {
        $groups = $this->load();
        foreach ($groups as $g) {
            if ((string)$g['id'] === (string)$id) return response()->json(['data' => $g]);
        }
        return response()->json(['message' => 'Not found'], 404);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $data = $request->validate([
            'careerCode' => ['sometimes', 'string', 'max:32'],
            'plan' => ['sometimes', 'in:nuevo-modelo,plan-normal'],
            'cuatrimestre' => ['sometimes', 'integer'],
            'groupNumber' => ['sometimes', 'integer'],
        ]);

        $groups = $this->load();
        $found = false;
        foreach ($groups as &$g) {
            if ((string)$g['id'] === (string)$id) {
                $found = true;
                if (isset($data['careerCode'])) $g['careerCode'] = strtoupper($data['careerCode']);
                if (isset($data['plan'])) $g['plan'] = $data['plan'];
                if (isset($data['cuatrimestre'])) $g['cuatrimestre'] = (int)$data['cuatrimestre'];
                if (isset($data['groupNumber'])) $g['groupNumber'] = (int)$data['groupNumber'];
                $g['name'] = $g['careerCode'] . $g['cuatrimestre'] . '-' . $g['groupNumber'];
                break;
            }
        }
        unset($g);

        if (!$found) return response()->json(['message' => 'Not found'], 404);

        $this->save($groups);

        // return the updated item
        foreach ($groups as $g) if ((string)$g['id'] === (string)$id) return response()->json(['data' => $g]);

        return response()->json(['message' => 'Not found'], 404);
    }

    public function destroy($id): JsonResponse
    {
        $groups = $this->load();
        $next = array_values(array_filter($groups, function ($g) use ($id) {
            return (string)$g['id'] !== (string)$id;
        }));

        $this->save($next);

        return response()->json(['data' => null], 204);
    }
}
