import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// In-app / foreground notifications (Android 13+ permission + default channel).
class LocalNotificationsService {
  LocalNotificationsService();

  static final LocalNotificationsService instance = LocalNotificationsService();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;
  final _tapController = StreamController<Map<String, dynamic>>.broadcast();

  Stream<Map<String, dynamic>> get onTap => _tapController.stream;

  static const AndroidNotificationChannel defaultChannel = AndroidNotificationChannel(
    'flc_default',
    'Foreign Learner',
    description: 'Vocab quiz reminders and study notifications',
    importance: Importance.high,
  );

  Future<void> init() async {
    if (_ready) return;

    const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
    // Permissions are requested by FCM; avoid duplicate iOS permission prompts.
    const darwinInit = DarwinInitializationSettings(
      requestAlertPermission: false,
      requestBadgePermission: false,
      requestSoundPermission: false,
    );

    await _plugin.initialize(
      const InitializationSettings(android: androidInit, iOS: darwinInit),
      onDidReceiveNotificationResponse: (NotificationResponse response) {
        final raw = response.payload;
        if (raw == null || raw.trim().isEmpty) return;
        try {
          final decoded = jsonDecode(raw);
          if (decoded is Map<String, dynamic>) {
            _tapController.add(decoded);
          } else if (decoded is Map) {
            _tapController.add(Map<String, dynamic>.from(decoded));
          }
        } catch (e) {
          if (kDebugMode) debugPrint('[LOCAL_NOTI] payload decode failed: $e');
        }
      },
    );

    // Android 13+ runtime notification permission (FCM requestPermission alone is not enough).
    await _plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.requestNotificationsPermission();

    await _plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(defaultChannel);

    _ready = true;
    if (kDebugMode) debugPrint('[LOCAL_NOTI] ready');
  }

  Future<void> show({
    required String title,
    required String body,
    String? payload,
  }) async {
    if (!_ready) {
      await init();
    }

    final details = NotificationDetails(
      android: AndroidNotificationDetails(
        defaultChannel.id,
        defaultChannel.name,
        channelDescription: defaultChannel.description,
        importance: defaultChannel.importance,
        priority: Priority.high,
      ),
      iOS: const DarwinNotificationDetails(),
    );

    final id = DateTime.now().millisecondsSinceEpoch.remainder(1 << 31);
    try {
      await _plugin.show(id, title, body, details, payload: payload);
    } catch (e) {
      if (kDebugMode) debugPrint('[LOCAL_NOTI] show failed: $e');
    }
  }

  Future<void> dispose() async {
    await _tapController.close();
  }
}
