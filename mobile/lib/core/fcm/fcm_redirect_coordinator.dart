import 'package:flc_mobile/core/fcm/fcm_service.dart';
import 'package:flutter/foundation.dart';

typedef WebAppPathHandler = void Function(String path);

class FcmRedirectCoordinator {
  FcmRedirectCoordinator(this._fcm);

  final FcmService _fcm;
  WebAppPathHandler? _handler;
  String? _queued;

  void start(WebAppPathHandler handler) {
    _handler = handler;
    _fcm.routes.listen(_handleRoute);

    final pending = _fcm.consumePendingRoute();
    if (pending != null) {
      _handleRoute(pending);
    }
  }

  void _handleRoute(String route) {
    if (route.isEmpty) return;
    if (kDebugMode) debugPrint('[FCM_REDIRECT] route=$route');

    final handler = _handler;
    if (handler != null) {
      handler(route);
      return;
    }

    _queued = route;
  }

  void attachHandler(WebAppPathHandler handler) {
    _handler = handler;
    final queued = _queued;
    if (queued != null) {
      _queued = null;
      handler(queued);
    }
  }

  Future<void> dispose() async {}
}
