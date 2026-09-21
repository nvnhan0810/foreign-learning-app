<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListeningAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListeningAssessmentController extends Controller
{
    public function index(Request $request): View
    {
        $query = ListeningAssessment::query()
            ->with(['user', 'mediaItem'])
            ->withCount('attempts')
            ->latest();

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('mediaItem', fn ($mq) => $mq->where('title', 'like', '%'.$search.'%'));
            });
        }

        if ($type = $request->string('type')->trim()->toString()) {
            $query->where('type', $type);
        }

        if ($status = $request->string('status')->trim()->toString()) {
            $query->where('status', $status);
        }

        return view('admin.listening-assessments.index', [
            'assessments' => $query->paginate(25)->withQueryString(),
            'search' => $search ?? '',
            'type' => $type ?? '',
            'status' => $status ?? '',
        ]);
    }

    public function show(ListeningAssessment $listeningAssessment): View
    {
        $listeningAssessment->load([
            'user',
            'mediaItem',
            'attempts' => fn ($q) => $q->with('user')->latest()->limit(20),
        ]);

        $questions = $listeningAssessment->sessionQuestions();

        return view('admin.listening-assessments.show', [
            'assessment' => $listeningAssessment,
            'questions' => $questions,
        ]);
    }

    public function destroy(ListeningAssessment $listeningAssessment): RedirectResponse
    {
        $mediaItemId = $listeningAssessment->media_item_id;
        $listeningAssessment->delete();

        return redirect()->route('admin.media-items.show', $mediaItemId)
            ->with('success', 'Đã xóa phiên làm bài.');
    }

    public function regenerate(ListeningAssessment $listeningAssessment): RedirectResponse
    {
        return back()->with('error', 'Câu hỏi được quản lý qua ngân hàng câu hỏi trên trang media. Tạo lại ngân hàng ở đó.');
    }
}
