<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first()
                ->toArray();
            if (!$knowledge) abort(500, __('Article does not exist'));
            $user = User::find($request->user['id']);
            $this->formatAccessData($knowledge['body'], $user);
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge['body']
            );
            $knowledge['body'] = str_replace('{{subscribeToken}}', $user['token'], $knowledge['body']);
            return response([
                'data' => $knowledge
            ]);
        }
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');
        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->groupBy('category');
        return response([
            'data' => $knowledges
        ]);
    }

    private function formatAccessData(&$body, User $user)
    {
        $userService = new UserService();
        $body = preg_replace_callback('/<!--access start(?P<config>.*?)-->(?P<content>.*?)<!--access end-->/s', function ($matches) use ($user, $userService) {
            $planIds = $this->getAccessPlanIds($matches['config'] ?? '');
            $hasAccess = $userService->isAvailable($user);

            if ($hasAccess && $planIds) {
                $hasAccess = in_array((int)$user->plan_id, $planIds, true);
            }

            if ($hasAccess) {
                return $matches['content'];
            }

            return '<div class="v2board-no-access">'. __('You must have a valid subscription to view content in this area') .'</div>';
        }, $body);
    }

    private function getAccessPlanIds(string $config): array
    {
        $config = trim($config);
        if ($config === '') {
            return [];
        }

        if (strpos($config, ':') === 0) {
            $config = substr($config, 1);
        } elseif (preg_match('/plan_ids?\s*=\s*([0-9,\s]+)/i', $config, $matches)) {
            $config = $matches[1];
        }

        $planIds = array_filter(array_map('trim', explode(',', $config)), function ($value) {
            return $value !== '' && is_numeric($value);
        });

        return array_values(array_unique(array_map('intval', $planIds)));
    }
}
