import 'package:flutter_dotenv/flutter_dotenv.dart';

String get webAppUrl {
  final fromEnv = dotenv.env['WEBAPP_URL']?.trim();
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;
  const fromDefine = String.fromEnvironment('WEBAPP_URL');
  if (fromDefine.isNotEmpty) return fromDefine;
  return 'https://flc.nvnhan0810.com';
}

String get apiBaseUrl {
  final fromEnv = dotenv.env['API_BASE_URL']?.trim();
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;
  const fromDefine = String.fromEnvironment('API_BASE_URL');
  if (fromDefine.isNotEmpty) return fromDefine;
  return 'https://flc.nvnhan0810.com/api';
}

const String oauthRedirectUri = 'flc://oauth-callback';
