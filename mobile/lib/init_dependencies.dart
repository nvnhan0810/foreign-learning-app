import 'package:flc_mobile/core/api/api_client.dart';
import 'package:flc_mobile/core/api/flc_api.dart';
import 'package:flc_mobile/core/auth/auth_service.dart';
import 'package:flc_mobile/core/fcm/fcm_redirect_coordinator.dart';
import 'package:flc_mobile/core/fcm/fcm_service.dart';
import 'package:flc_mobile/core/fcm/fcm_token_registrar.dart';
import 'package:flc_mobile/core/notification/local_notifications_service.dart';
import 'package:flc_mobile/core/storage/token_storage.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';

late final TokenStorage appTokenStorage;
late final ApiClient appApiClient;
late final FlcApi appFlcApi;
late final AuthService appAuthService;
late final FcmService appFcmService;
late final FcmTokenRegistrar appFcmTokenRegistrar;
late final FcmRedirectCoordinator appFcmRedirectCoordinator;

Future<void> initDependencies() async {
  WidgetsFlutterBinding.ensureInitialized();

  try {
    await dotenv.load(fileName: '.env');
  } catch (_) {
    await dotenv.load(fileName: '.env.example');
  }

  appTokenStorage = TokenStorage();
  appApiClient = ApiClient(appTokenStorage);
  appFlcApi = FlcApi(appApiClient);
  appAuthService = AuthService(appTokenStorage, appFlcApi);
  appFcmService = FcmService(localNoti: LocalNotificationsService.instance);

  try {
    await LocalNotificationsService.instance.init();
  } catch (_) {}
  try {
    await appFcmService.init();
  } catch (_) {}

  appFcmTokenRegistrar = FcmTokenRegistrar(
    fcm: appFcmService,
    api: appFlcApi,
    auth: appAuthService,
  );
  appFcmRedirectCoordinator = FcmRedirectCoordinator(appFcmService);

  try {
    await appFcmTokenRegistrar.start();
  } catch (_) {}
}
