import 'package:flc_mobile/app.dart';
import 'package:flc_mobile/init_dependencies.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

Future<void> main() async {
  await initDependencies();
  runApp(const ProviderScope(child: FlcApp()));
}
