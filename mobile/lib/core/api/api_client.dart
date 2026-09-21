import 'package:dio/dio.dart';
import 'package:flc_mobile/config/app_config.dart';
import 'package:flc_mobile/core/storage/token_storage.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient(this._tokenStorage) {
    _dio = Dio(
      BaseOptions(
        baseUrl: apiBaseUrl,
        connectTimeout: const Duration(seconds: 30),
        receiveTimeout: const Duration(seconds: 60),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _tokenStorage.getToken();
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          if (error.response?.statusCode == 401) {
            await _tokenStorage.clear();
          }
          handler.next(error);
        },
      ),
    );
  }

  final TokenStorage _tokenStorage;
  late final Dio _dio;

  Dio get dio => _dio;

  Future<T> get<T>(String path, {T Function(dynamic json)? parser}) async {
    return _wrap(() async {
      final res = await _dio.get<dynamic>(path);
      return _parse(res.data, parser);
    });
  }

  Future<T> post<T>(
    String path, {
    Object? data,
    T Function(dynamic json)? parser,
  }) async {
    return _wrap(() async {
      final res = await _dio.post<dynamic>(path, data: data);
      return _parse(res.data, parser);
    });
  }

  Future<T> put<T>(
    String path, {
    Object? data,
    T Function(dynamic json)? parser,
  }) async {
    return _wrap(() async {
      final res = await _dio.put<dynamic>(path, data: data);
      return _parse(res.data, parser);
    });
  }

  Future<T> delete<T>(
    String path, {
    Object? data,
    T Function(dynamic json)? parser,
  }) async {
    return _wrap(() async {
      final res = await _dio.delete<dynamic>(path, data: data);
      return _parse(res.data, parser);
    });
  }

  T _parse<T>(dynamic data, T Function(dynamic json)? parser) {
    if (parser != null) return parser(data);
    return data as T;
  }

  Future<T> _wrap<T>(Future<T> Function() fn) async {
    try {
      return await fn();
    } on DioException catch (e) {
      final data = e.response?.data;
      var message = 'API connection error';
      if (data is Map && data['message'] != null) {
        message = data['message'].toString();
      } else if (e.message != null) {
        message = e.message!;
      }
      throw ApiException(message, statusCode: e.response?.statusCode);
    }
  }
}
