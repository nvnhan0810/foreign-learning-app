import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flc_mobile/core/notification/local_notifications_service.dart';
import 'package:flutter/foundation.dart';

@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
}

class FcmService {
  FcmService({required LocalNotificationsService localNoti}) : _localNoti = localNoti;

  final LocalNotificationsService _localNoti;

  FirebaseMessaging get _messaging => FirebaseMessaging.instance;

  Stream<String> get onTokenRefresh => _messaging.onTokenRefresh;

  StreamSubscription<String>? _tokenSub;
  StreamSubscription<RemoteMessage>? _onMessageSub;
  StreamSubscription<RemoteMessage>? _onMessageOpenedSub;
  StreamSubscription<Map<String, dynamic>>? _localTapSub;

  final _routeController = StreamController<String>.broadcast();
  Stream<String> get routes => _routeController.stream;
  String? _pendingRoute;
  bool _initialized = false;

  String? consumePendingRoute() {
    final route = _pendingRoute;
    _pendingRoute = null;
    return route;
  }

  Future<void> init() async {
    if (_initialized) return;

    await Firebase.initializeApp();

    await _messaging.requestPermission(alert: true, badge: true, sound: true);

    // Foreground: only flutter_local_notifications shows UI (same as order_app).
    // Without this, iOS also displays the FCM/APNs banner → duplicate notifications.
    if (Platform.isIOS) {
      await _messaging.setForegroundNotificationPresentationOptions(
        alert: false,
        badge: false,
        sound: false,
      );
    }

    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

    // Foreground: show in-app (local) notification only.
    _onMessageSub = FirebaseMessaging.onMessage.listen(_handleForegroundMessage);
    _onMessageOpenedSub =
        FirebaseMessaging.onMessageOpenedApp.listen(_handleNotificationOpenMessage);

    final initial = await _messaging.getInitialMessage();
    if (initial != null) {
      _handleNotificationOpenMessage(initial);
    }

    _localTapSub = _localNoti.onTap.listen((data) {
      final route = _routeFromData(data);
      if (route == null) return;
      _pendingRoute = route;
      _routeController.add(route);
    });

    _tokenSub = _messaging.onTokenRefresh.listen((_) {});
    _initialized = true;
  }

  Future<String?> getToken() => _messaging.getToken();

  void _handleForegroundMessage(RemoteMessage message) {
    // Foreground: Firebase does not show notification UI by default on Android.
    // On iOS we disable native presentation above; show one local notification here.
    final n = message.notification;
    final title = (n?.title?.trim().isNotEmpty ?? false)
        ? n!.title!.trim()
        : (message.data['title']?.toString().trim() ?? '');
    final body = (n?.body?.trim().isNotEmpty ?? false)
        ? n!.body!.trim()
        : (message.data['body']?.toString().trim() ?? '');

    if (title.isNotEmpty || body.isNotEmpty) {
      _localNoti.show(
        title: title.isNotEmpty ? title : 'Foreign Learner',
        body: body,
        payload: message.data.isNotEmpty ? jsonEncode(message.data) : null,
      );
    }
  }

  void _handleNotificationOpenMessage(RemoteMessage message) {
    final route = _routeFromData(message.data);
    if (route == null) return;

    if (kDebugMode) {
      debugPrint('[FCM] open notification route=$route data=${message.data}');
    }

    _pendingRoute = route;
    _routeController.add(route);
  }

  static String? _routeFromData(Map<String, dynamic> data) {
    if (data['type'] == 'vocab_quiz') {
      return '/home/quiz/play?autostart=1';
    }

    final route = data['route'];
    if (route is String && route.isNotEmpty) {
      final autostart = data['autostart'] == '1' ? '?autostart=1' : '';
      return route.contains('?') ? route : '$route$autostart';
    }

    return null;
  }

  Future<void> dispose() async {
    await _tokenSub?.cancel();
    await _onMessageSub?.cancel();
    await _onMessageOpenedSub?.cancel();
    await _localTapSub?.cancel();
    await _routeController.close();
  }
}
