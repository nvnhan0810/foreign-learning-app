<?php

namespace App\Providers;

use Flc\AdminSettings\Application\Command\SetAppSetting;
use Flc\AdminSettings\Application\Command\SetAppSettings;
use Flc\AdminSettings\Application\Handler\GetAppSettingHandler;
use Flc\AdminSettings\Application\Handler\GetAppSettingsHandler;
use Flc\AdminSettings\Application\Handler\SetAppSettingHandler;
use Flc\AdminSettings\Application\Handler\SetAppSettingsHandler;
use Flc\AdminSettings\Application\Query\GetAppSetting;
use Flc\AdminSettings\Application\Query\GetAppSettings;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\AdminSettings\Infrastructure\Persistence\EloquentAppSettingsRepository;
use Flc\Dictionary\Application\Command\CurateDictionaryEntry;
use Flc\Dictionary\Application\Command\DeleteDictionaryEntry;
use Flc\Dictionary\Application\Command\UpsertDictionaryOnSave;
use Flc\Dictionary\Application\FreeDictionaryGateway;
use Flc\Dictionary\Application\Handler\CurateDictionaryEntryHandler;
use Flc\Dictionary\Application\Handler\DeleteDictionaryEntryHandler;
use Flc\Dictionary\Application\Handler\LookupWordHandler;
use Flc\Dictionary\Application\Handler\ResolveLookupWordHandler;
use Flc\Dictionary\Application\Handler\UpsertDictionaryOnSaveHandler;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\Query\ResolveLookupWord;
use Flc\Dictionary\Application\RelatedWordsGateway;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Application\SpellSuggestionGateway;
use Flc\Dictionary\Infrastructure\Http\HttpDatamuseRelatedWordsGateway;
use Flc\Dictionary\Infrastructure\Http\HttpDatamuseSpellSuggestionGateway;
use Flc\Dictionary\Infrastructure\Http\HttpFreeDictionaryGateway;
use Flc\Dictionary\Infrastructure\Persistence\EloquentDictionaryEntryRepository;
use Flc\Identity\Application\Command\CreateAllowedEmail;
use Flc\Identity\Application\Command\DeleteAllowedEmail;
use Flc\Identity\Application\Command\UpdateAllowedEmail;
use Flc\Identity\Application\Handler\CountActiveAllowedEmailsHandler;
use Flc\Identity\Application\Handler\CreateAllowedEmailHandler;
use Flc\Identity\Application\Handler\DeleteAllowedEmailHandler;
use Flc\Identity\Application\Handler\GetAllowedEmailHandler;
use Flc\Identity\Application\Handler\IsEmailAllowedHandler;
use Flc\Identity\Application\Handler\ListAllowedEmailsHandler;
use Flc\Identity\Application\Handler\UpdateAllowedEmailHandler;
use Flc\Identity\Application\Query\CountActiveAllowedEmails;
use Flc\Identity\Application\Query\GetAllowedEmail;
use Flc\Identity\Application\Query\IsEmailAllowed;
use Flc\Identity\Application\Query\ListAllowedEmails;
use Flc\Identity\Application\Repository\AllowedEmailRepository;
use Flc\Identity\Infrastructure\Persistence\EloquentAllowedEmailRepository;
use Flc\Listening\Application\Command\InitializeSessionQuestions;
use Flc\Listening\Application\Command\ResumeOrStartListeningSession;
use Flc\Listening\Application\Command\StartListeningSession;
use Flc\Listening\Application\Command\SubmitListeningAttempt;
use Flc\Listening\Application\Handler\GetListeningAssessmentQuestionsHandler;
use Flc\Listening\Application\Handler\GetListeningAttemptsHandler;
use Flc\Listening\Application\Handler\GetListeningSessionOptionsHandler;
use Flc\Listening\Application\Handler\InitializeSessionQuestionsHandler;
use Flc\Listening\Application\Handler\ResumeOrStartListeningSessionHandler;
use Flc\Listening\Application\Handler\StartListeningSessionHandler;
use Flc\Listening\Application\Handler\SubmitListeningAttemptHandler;
use Flc\Listening\Application\Query\GetListeningAssessmentQuestions;
use Flc\Listening\Application\Query\GetListeningAttempts;
use Flc\Listening\Application\Query\GetListeningSessionOptions;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Infrastructure\Persistence\EloquentListeningAssessmentRepository;
use Flc\Media\Application\Command\ProcessMediaContent;
use Flc\Media\Application\ContentAnalyzer;
use Flc\Media\Application\Handler\ProcessMediaContentHandler;
use Flc\Media\Application\ListeningAssessmentGenerator;
use Flc\Media\Application\MediaContentResolver;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Media\Infrastructure\Content\DefaultContentAnalyzer;
use Flc\Media\Infrastructure\Content\DefaultListeningAssessmentGenerator;
use Flc\Media\Infrastructure\Content\DefaultMediaContentResolver;
use Flc\Media\Infrastructure\Persistence\EloquentMediaItemRepository;
use Flc\Notification\Application\Command\SendVocabQuizReminders;
use Flc\Notification\Application\Command\UpdateUserNotificationPreference;
use Flc\Notification\Application\Handler\GetUserNotificationPreferenceHandler;
use Flc\Notification\Application\Handler\SendVocabQuizRemindersHandler;
use Flc\Notification\Application\Handler\UpdateUserNotificationPreferenceHandler;
use Flc\Notification\Application\PushNotifier;
use Flc\Notification\Application\Query\GetUserNotificationPreference;
use Flc\Notification\Application\Repository\UserNotificationPreferenceRepository;
use Flc\Notification\Application\Repository\VocabQuizReminderRepository;
use Flc\Notification\Infrastructure\External\FcmPushNotifier;
use Flc\Notification\Infrastructure\Persistence\EloquentUserNotificationPreferenceRepository;
use Flc\Notification\Infrastructure\Persistence\EloquentVocabQuizReminderRepository;
use Flc\Puzzle\Application\Handler\GetNextHangmanPuzzleHandler;
use Flc\Puzzle\Application\Handler\GetNextWordSearchPuzzleHandler;
use Flc\Puzzle\Application\Handler\GetNextScramblePuzzleHandler;
use Flc\Puzzle\Application\Handler\GetNextWordlePuzzleHandler;
use Flc\Puzzle\Application\Handler\GetScrambleHintHandler;
use Flc\Puzzle\Application\Query\GetNextHangmanPuzzle;
use Flc\Puzzle\Application\Query\GetNextWordSearchPuzzle;
use Flc\Puzzle\Application\Query\GetNextScramblePuzzle;
use Flc\Puzzle\Application\Query\GetNextWordlePuzzle;
use Flc\Puzzle\Application\Query\GetScrambleHint;
use Flc\Quiz\Application\Command\RecordQuizAttempt;
use Flc\Quiz\Application\Handler\GetNextQuizQuestionHandler;
use Flc\Quiz\Application\Handler\RecordQuizAttemptHandler;
use Flc\Quiz\Application\Query\GetNextQuizQuestion;
use Flc\Quiz\Application\Repository\QuizAttemptRepository;
use Flc\Quiz\Infrastructure\Persistence\EloquentQuizAttemptRepository;
use Flc\Shared\Application\Clock;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\Config;
use Flc\Shared\Application\Logger;
use Flc\Shared\Application\QueryBus;
use Flc\Shared\Infrastructure\LaravelClock;
use Flc\Shared\Infrastructure\LaravelConfig;
use Flc\Shared\Infrastructure\LaravelLogger;
use Flc\Vocabulary\Application\Command\DeleteUserVocabulary;
use Flc\Vocabulary\Application\Command\SaveUserVocabulary;
use Flc\Vocabulary\Application\Command\UpdateUserVocabulary;
use Flc\Vocabulary\Application\Handler\DeleteUserVocabularyHandler;
use Flc\Vocabulary\Application\Handler\FindUserVocabularyByWordHandler;
use Flc\Vocabulary\Application\Handler\GetUserVocabularyHandler;
use Flc\Vocabulary\Application\Handler\ListUserVocabulariesHandler;
use Flc\Vocabulary\Application\Handler\SaveUserVocabularyHandler;
use Flc\Vocabulary\Application\Handler\UpdateUserVocabularyHandler;
use Flc\Vocabulary\Application\Query\FindUserVocabularyByWord;
use Flc\Vocabulary\Application\Query\GetUserVocabulary;
use Flc\Vocabulary\Application\Query\ListUserVocabularies;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Infrastructure\Persistence\EloquentUserVocabularyRepository;
use Flc\WordChat\Application\Command\CompleteWordChatRun;
use Flc\WordChat\Application\Command\CreateWordChatAgent;
use Flc\WordChat\Application\Command\EnsureWordChatAgent;
use Flc\WordChat\Application\Command\ResetWordChatAgent;
use Flc\WordChat\Application\Command\SendWordChatMessage;
use Flc\WordChat\Application\CursorWordChatGateway;
use Flc\WordChat\Application\Handler\CompleteWordChatRunHandler;
use Flc\WordChat\Application\Handler\CreateWordChatAgentHandler;
use Flc\WordChat\Application\Handler\EnsureWordChatAgentHandler;
use Flc\WordChat\Application\Handler\GetWordChatAgentStatusHandler;
use Flc\WordChat\Application\Handler\ListLearningInsightsHandler;
use Flc\WordChat\Application\Handler\ListWordChatMessagesHandler;
use Flc\WordChat\Application\Handler\ResetWordChatAgentHandler;
use Flc\WordChat\Application\Handler\SendWordChatMessageHandler;
use Flc\WordChat\Application\LearningInsightRepository;
use Flc\WordChat\Application\Query\GetWordChatAgentStatus;
use Flc\WordChat\Application\Query\ListLearningInsights;
use Flc\WordChat\Application\Query\ListWordChatMessages;
use Flc\WordChat\Application\WordChatAgentRepository;
use Flc\WordChat\Application\WordChatInsightExtractor;
use Flc\WordChat\Application\WordChatMessageRepository;
use Flc\WordChat\Application\WordChatRunRepository;
use Flc\WordChat\Application\WordChatStreamProxy;
use Flc\WordChat\Infrastructure\External\HttpCursorWordChatGateway as CursorWordChatGatewayImpl;
use Flc\WordChat\Infrastructure\Persistence\EloquentLearningInsightRepository;
use Flc\WordChat\Infrastructure\Persistence\EloquentWordChatAgentRepository;
use Flc\WordChat\Infrastructure\Persistence\EloquentWordChatMessageRepository;
use Flc\WordChat\Infrastructure\Persistence\EloquentWordChatRunRepository;
use Illuminate\Support\ServiceProvider;

class FlcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DictionaryEntryRepository::class, EloquentDictionaryEntryRepository::class);
        $this->app->bind(UserVocabularyRepository::class, EloquentUserVocabularyRepository::class);
        $this->app->bind(QuizAttemptRepository::class, EloquentQuizAttemptRepository::class);
        $this->app->bind(UserNotificationPreferenceRepository::class, EloquentUserNotificationPreferenceRepository::class);
        $this->app->bind(VocabQuizReminderRepository::class, EloquentVocabQuizReminderRepository::class);
        $this->app->bind(PushNotifier::class, FcmPushNotifier::class);
        $this->app->bind(AppSettingsRepository::class, EloquentAppSettingsRepository::class);
        $this->app->bind(AllowedEmailRepository::class, EloquentAllowedEmailRepository::class);
        $this->app->bind(FreeDictionaryGateway::class, HttpFreeDictionaryGateway::class);
        $this->app->bind(RelatedWordsGateway::class, HttpDatamuseRelatedWordsGateway::class);
        $this->app->bind(SpellSuggestionGateway::class, HttpDatamuseSpellSuggestionGateway::class);
        $this->app->bind(WordChatAgentRepository::class, EloquentWordChatAgentRepository::class);
        $this->app->bind(LearningInsightRepository::class, EloquentLearningInsightRepository::class);
        $this->app->bind(WordChatMessageRepository::class, EloquentWordChatMessageRepository::class);
        $this->app->bind(WordChatRunRepository::class, EloquentWordChatRunRepository::class);
        $this->app->bind(CursorWordChatGateway::class, CursorWordChatGatewayImpl::class);
        $this->app->singleton(WordChatStreamProxy::class);
        $this->app->singleton(WordChatInsightExtractor::class);
        $this->app->bind(MediaItemRepository::class, EloquentMediaItemRepository::class);
        $this->app->bind(MediaContentResolver::class, DefaultMediaContentResolver::class);
        $this->app->bind(ContentAnalyzer::class, DefaultContentAnalyzer::class);
        $this->app->bind(ListeningAssessmentGenerator::class, DefaultListeningAssessmentGenerator::class);
        $this->app->bind(ListeningAssessmentRepository::class, EloquentListeningAssessmentRepository::class);
        $this->app->bind(Clock::class, LaravelClock::class);
        $this->app->bind(Config::class, LaravelConfig::class);
        $this->app->bind(Logger::class, LaravelLogger::class);

        $this->app->singleton(CommandBus::class, function ($app) {
            return new CommandBus($this->commandMap(), $app);
        });

        $this->app->singleton(QueryBus::class, function ($app) {
            return new QueryBus($this->queryMap(), $app);
        });
    }

    /** @return array<class-string, class-string> */
    private function commandMap(): array
    {
        return [
            UpsertDictionaryOnSave::class => UpsertDictionaryOnSaveHandler::class,
            CurateDictionaryEntry::class => CurateDictionaryEntryHandler::class,
            DeleteDictionaryEntry::class => DeleteDictionaryEntryHandler::class,
            SaveUserVocabulary::class => SaveUserVocabularyHandler::class,
            UpdateUserVocabulary::class => UpdateUserVocabularyHandler::class,
            DeleteUserVocabulary::class => DeleteUserVocabularyHandler::class,
            SetAppSettings::class => SetAppSettingsHandler::class,
            SetAppSetting::class => SetAppSettingHandler::class,
            CreateAllowedEmail::class => CreateAllowedEmailHandler::class,
            UpdateAllowedEmail::class => UpdateAllowedEmailHandler::class,
            DeleteAllowedEmail::class => DeleteAllowedEmailHandler::class,
            RecordQuizAttempt::class => RecordQuizAttemptHandler::class,
            SendVocabQuizReminders::class => SendVocabQuizRemindersHandler::class,
            UpdateUserNotificationPreference::class => UpdateUserNotificationPreferenceHandler::class,
            ProcessMediaContent::class => ProcessMediaContentHandler::class,
            StartListeningSession::class => StartListeningSessionHandler::class,
            ResumeOrStartListeningSession::class => ResumeOrStartListeningSessionHandler::class,
            InitializeSessionQuestions::class => InitializeSessionQuestionsHandler::class,
            SubmitListeningAttempt::class => SubmitListeningAttemptHandler::class,
            SendWordChatMessage::class => SendWordChatMessageHandler::class,
            CompleteWordChatRun::class => CompleteWordChatRunHandler::class,
            ResetWordChatAgent::class => ResetWordChatAgentHandler::class,
            EnsureWordChatAgent::class => EnsureWordChatAgentHandler::class,
            CreateWordChatAgent::class => CreateWordChatAgentHandler::class,
        ];
    }

    /** @return array<class-string, class-string> */
    private function queryMap(): array
    {
        return [
            LookupWord::class => LookupWordHandler::class,
            ResolveLookupWord::class => ResolveLookupWordHandler::class,
            ListUserVocabularies::class => ListUserVocabulariesHandler::class,
            GetUserVocabulary::class => GetUserVocabularyHandler::class,
            FindUserVocabularyByWord::class => FindUserVocabularyByWordHandler::class,
            GetAppSettings::class => GetAppSettingsHandler::class,
            GetAppSetting::class => GetAppSettingHandler::class,
            IsEmailAllowed::class => IsEmailAllowedHandler::class,
            ListAllowedEmails::class => ListAllowedEmailsHandler::class,
            GetAllowedEmail::class => GetAllowedEmailHandler::class,
            CountActiveAllowedEmails::class => CountActiveAllowedEmailsHandler::class,
            GetNextQuizQuestion::class => GetNextQuizQuestionHandler::class,
            GetNextHangmanPuzzle::class => GetNextHangmanPuzzleHandler::class,
            GetNextWordSearchPuzzle::class => GetNextWordSearchPuzzleHandler::class,
            GetNextScramblePuzzle::class => GetNextScramblePuzzleHandler::class,
            GetNextWordlePuzzle::class => GetNextWordlePuzzleHandler::class,
            GetScrambleHint::class => GetScrambleHintHandler::class,
            GetUserNotificationPreference::class => GetUserNotificationPreferenceHandler::class,
            GetListeningSessionOptions::class => GetListeningSessionOptionsHandler::class,
            GetListeningAssessmentQuestions::class => GetListeningAssessmentQuestionsHandler::class,
            GetListeningAttempts::class => GetListeningAttemptsHandler::class,
            ListWordChatMessages::class => ListWordChatMessagesHandler::class,
            ListLearningInsights::class => ListLearningInsightsHandler::class,
            GetWordChatAgentStatus::class => GetWordChatAgentStatusHandler::class,
        ];
    }
}
