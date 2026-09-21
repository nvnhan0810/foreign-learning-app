<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaContentJob;
use App\Models\MediaItem;
use App\Models\User;
use Flc\Media\Application\ListeningAssessmentGenerator;
use Flc\Media\Infrastructure\External\YouTubeUrlParser;
use Flc\Media\Infrastructure\Scheduling\MediaScheduleService;
use Flc\Media\Infrastructure\Storage\MediaStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MediaItemController extends Controller
{
    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly YouTubeUrlParser $youtubeParser,
        private readonly ListeningAssessmentGenerator $assessmentGenerator,
    ) {}

    public function index(Request $request): View
    {
        $query = MediaItem::query()
            ->with(['user', 'listeningAssessments'])
            ->latest();

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('url', 'like', '%'.$search.'%');
            });
        }

        if ($type = $request->string('type')->trim()->toString()) {
            $query->where('type', $type);
        }

        if ($status = $request->string('analysis_status')->trim()->toString()) {
            $query->where('analysis_status', $status);
        }

        return view('admin.media-items.index', [
            'mediaItems' => $query->paginate(25)->withQueryString(),
            'search' => $search ?? '',
            'type' => $type ?? '',
            'analysisStatus' => $status ?? '',
        ]);
    }

    public function create(): View
    {
        return view('admin.media-items.create', [
            'users' => User::query()->orderBy('email')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request, MediaScheduleService $schedule): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:youtube,mp3,audio'],
            'url' => ['required_if:type,youtube', 'nullable', 'url', 'max:2048'],
            'audio' => [
                Rule::requiredIf(in_array($request->input('type'), ['mp3', 'audio'], true)),
                'nullable',
                'file',
                'max:'.(config('listening.max_audio_size_mb') * 1024),
                'mimetypes:'.implode(',', config('listening.allowed_audio_mimes')),
            ],
            'language' => ['sometimes', 'string', 'max:10'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'notes' => ['nullable', 'string'],
            'transcript' => ['nullable', 'string'],
            'auto_process' => ['sometimes', 'boolean'],
        ]);

        $userId = (int) $data['user_id'];
        $type = $data['type'];
        $autoProcess = $request->boolean('auto_process', true);

        $payload = [
            'user_id' => $userId,
            'title' => $data['title'],
            'type' => $type,
            'language' => $data['language'] ?? 'en',
            'frequency' => $data['frequency'] ?? 'weekly',
            'difficulty' => MediaItem::normalizeDifficulty($data['difficulty'] ?? null),
            'notes' => $data['notes'] ?? null,
            'transcript' => $data['transcript'] ?? null,
            'is_active' => true,
            'analysis_status' => MediaItem::ANALYSIS_PENDING,
            'next_listen_at' => $schedule->initialNextListenAt($data['frequency'] ?? 'weekly'),
        ];

        if ($type === MediaItem::TYPE_YOUTUBE) {
            $videoId = $this->youtubeParser->extractVideoId($data['url']);

            if (! $videoId) {
                return back()->withInput()->withErrors(['url' => 'YouTube URL không hợp lệ.']);
            }

            $payload['url'] = $data['url'];
            $payload['source_id'] = $videoId;
        } else {
            /** @var \Illuminate\Http\UploadedFile $file */
            $file = $request->file('audio');
            $stored = $this->storage->storeAudio($file, $userId);

            $payload['url'] = $data['url'] ?? 'file://'.$stored['original_name'];
            $payload['audio_path'] = $stored['path'];
            $payload['audio_disk'] = $stored['disk'];
        }

        $item = MediaItem::query()->create($payload);

        if ($autoProcess) {
            ProcessMediaContentJob::dispatch($item->id);
        }

        return redirect()->route('admin.media-items.show', $item)
            ->with('success', $autoProcess
                ? 'Đã tạo media. Đang phân tích và tạo ngân hàng câu hỏi...'
                : 'Đã tạo media.');
    }

    public function show(MediaItem $mediaItem): View
    {
        $mediaItem->load([
            'user',
            'listeningAssessments' => fn ($q) => $q->withCount('attempts')->latest()->limit(10),
            'listeningQuestions' => fn ($q) => $q->orderBy('order'),
        ]);

        return view('admin.media-items.show', compact('mediaItem'));
    }

    public function edit(MediaItem $mediaItem): View
    {
        $mediaItem->load('user');

        return view('admin.media-items.edit', [
            'mediaItem' => $mediaItem,
            'users' => User::query()->orderBy('email')->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request, MediaItem $mediaItem, MediaScheduleService $schedule): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'type' => ['required', 'in:youtube,mp3,audio'],
            'language' => ['sometimes', 'string', 'max:10'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'notes' => ['nullable', 'string'],
            'transcript' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $frequencyChanged = $mediaItem->frequency !== $data['frequency'];

        $mediaItem->update([
            ...$data,
            'difficulty' => MediaItem::normalizeDifficulty($data['difficulty'] ?? $mediaItem->difficulty),
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($frequencyChanged) {
            $mediaItem->next_listen_at = $schedule->initialNextListenAt($data['frequency']);
            $mediaItem->save();
        }

        return redirect()->route('admin.media-items.show', $mediaItem)
            ->with('success', 'Đã cập nhật media.');
    }

    public function destroy(MediaItem $mediaItem): RedirectResponse
    {
        if ($mediaItem->audio_path) {
            Storage::disk($mediaItem->audio_disk)->delete($mediaItem->audio_path);
        }

        $mediaItem->delete();

        return redirect()->route('admin.media-items.index')
            ->with('success', 'Đã xóa media và các bài quiz/test/exam liên quan.');
    }

    public function process(MediaItem $mediaItem): RedirectResponse
    {
        if ($mediaItem->analysis_status === MediaItem::ANALYSIS_PROCESSING) {
            return back()->with('error', 'Phân tích đang chạy.');
        }

        $mediaItem->update([
            'analysis_status' => MediaItem::ANALYSIS_PENDING,
            'analysis_error' => null,
        ]);

        ProcessMediaContentJob::dispatch($mediaItem->id);

        return back()->with('success', 'Đã bắt đầu phân tích nội dung.');
    }

    public function regenerateAssessments(Request $request, MediaItem $mediaItem): RedirectResponse
    {
        if (! $mediaItem->isAnalysisReady()) {
            return back()->with('error', 'Media chưa phân tích xong. Chạy phân tích trước.');
        }

        try {
            $this->assessmentGenerator->generateQuestionBank($mediaItem->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Tạo ngân hàng câu hỏi thất bại: '.$e->getMessage());
        }

        return back()->with('success', 'Đã tạo lại ngân hàng câu hỏi.');
    }
}
