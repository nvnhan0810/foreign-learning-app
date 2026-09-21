import 'package:flc_mobile/core/theme/theme_storage.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final themeStorageProvider = Provider<ThemeStorage>((ref) => ThemeStorage());

final themePreferenceProvider =
    StateNotifierProvider<ThemePreferenceNotifier, ThemePreference>((ref) {
  return ThemePreferenceNotifier(ref.watch(themeStorageProvider));
});

class ThemePreferenceNotifier extends StateNotifier<ThemePreference> {
  ThemePreferenceNotifier(this._storage) : super(ThemePreference.system) {
    _load();
  }

  final ThemeStorage _storage;

  Future<void> _load() async {
    state = await _storage.get();
  }

  Future<void> setPreference(ThemePreference preference) async {
    state = preference;
    await _storage.set(preference);
  }
}

ThemeMode themePreferenceToMode(ThemePreference preference) {
  return switch (preference) {
    ThemePreference.light => ThemeMode.light,
    ThemePreference.dark => ThemeMode.dark,
    ThemePreference.system => ThemeMode.system,
  };
}
