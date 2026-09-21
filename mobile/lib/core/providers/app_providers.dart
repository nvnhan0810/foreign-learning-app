import 'package:flc_mobile/core/api/api_client.dart';
import 'package:flc_mobile/core/api/flc_api.dart';
import 'package:flc_mobile/core/auth/auth_service.dart';
import 'package:flc_mobile/core/fcm/fcm_token_registrar.dart';
import 'package:flc_mobile/core/storage/token_storage.dart';
import 'package:flc_mobile/init_dependencies.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final tokenStorageProvider = Provider<TokenStorage>((ref) => appTokenStorage);

final apiClientProvider = Provider<ApiClient>((ref) => appApiClient);

final flcApiProvider = Provider<FlcApi>((ref) => appFlcApi);

final authServiceProvider = Provider<AuthService>((ref) => appAuthService);

final fcmTokenRegistrarProvider =
    Provider<FcmTokenRegistrar>((ref) => appFcmTokenRegistrar);

final authStateProvider = FutureProvider<bool>((ref) async {
  return ref.watch(authServiceProvider).isLoggedIn();
});

/// Absolute or path URL the embedded WebView should navigate to (FCM / deep link).
final webAppNavigateProvider = StateProvider<String?>((ref) => null);
