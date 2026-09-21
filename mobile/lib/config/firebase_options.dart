import 'package:firebase_core/firebase_core.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';

/// Firebase options from `mobile/.env` (see `.env.example`).
class FirebaseOptionsFromEnv {
  static FirebaseOptions? tryLoad() {
    final projectId = dotenv.env['FIREBASE_PROJECT_ID']?.trim();
    final apiKey = dotenv.env['FIREBASE_API_KEY']?.trim();
    final appId = dotenv.env['FIREBASE_APP_ID']?.trim();
    final messagingSenderId = dotenv.env['FIREBASE_MESSAGING_SENDER_ID']?.trim();
    final iosBundleId = dotenv.env['FIREBASE_IOS_BUNDLE_ID']?.trim() ?? 'com.nvnhan0810.flc';

    if (projectId == null ||
        projectId.isEmpty ||
        apiKey == null ||
        apiKey.isEmpty ||
        appId == null ||
        appId.isEmpty ||
        messagingSenderId == null ||
        messagingSenderId.isEmpty) {
      return null;
    }

    return FirebaseOptions(
      apiKey: apiKey,
      appId: appId,
      messagingSenderId: messagingSenderId,
      projectId: projectId,
      iosBundleId: iosBundleId,
    );
  }
}
