require 'sinatra/base'
require 'json'
require 'socket'
require 'etc'
require 'time'

class App < Sinatra::Base
  STARTED_AT = Process.clock_gettime(Process::CLOCK_MONOTONIC)
  LOCK = Mutex.new
  STATE = { requests: 0, fail_until: { 'live' => 0.0, 'ready' => 0.0 } }

  set :public_folder, File.join(__dir__, 'public')
  # Behind the OpenShift router the Origin header may not match the backend's view of the URL.
  set :protection, except: [:http_origin]

  helpers do
    def probes
      now = Time.now.to_f
      LOCK.synchronize { STATE[:fail_until].transform_values { |t| now >= t } }
    end

    def pod_ip
      Socket.ip_address_list.find { |a| a.ipv4? && !a.ipv4_loopback? }&.ip_address
    end

    def namespace
      File.read('/var/run/secrets/kubernetes.io/serviceaccount/namespace').strip
    rescue StandardError
      ENV['POD_NAMESPACE'] || ENV['OPENSHIFT_BUILD_NAMESPACE']
    end

    def rss_mb
      kb = File.read('/proc/self/status')[/VmRSS:\s+(\d+)/, 1]
      kb && (kb.to_i / 1024.0).round
    rescue StandardError
      nil
    end

    # APP_* variables, with secret-looking names masked.
    def app_env
      ENV.select { |k, _| k.start_with?('APP_') }
         .to_h { |k, v| [k, k =~ /SECRET|PASSWORD|TOKEN|KEY/i ? '••••••••' : v] }
    end

    def seconds_param(default, max = 300)
      (params['seconds'] || default).to_i.clamp(0, max)
    end
  end

  before { LOCK.synchronize { STATE[:requests] += 1 } }

  get '/' do
    send_file File.join(settings.public_folder, 'index.html')
  end

  get '/api/info' do
    content_type :json
    cache_control :no_store
    {
      stack: 'Ruby',
      framework: "Sinatra #{Sinatra::VERSION}",
      runtime: RUBY_VERSION,
      accent: ENV['APP_COLOR'] || '#cc342d',
      message: ENV['APP_MESSAGE'] || 'Hello from Ruby (Sinatra) on OpenShift!',
      pod: { hostname: Socket.gethostname, ip: pod_ip, namespace: namespace, node: ENV['NODE_NAME'] },
      build: {
        name: ENV['OPENSHIFT_BUILD_NAME'],
        source: ENV['OPENSHIFT_BUILD_SOURCE'],
        reference: ENV['OPENSHIFT_BUILD_REFERENCE'],
        commit: ENV['OPENSHIFT_BUILD_COMMIT']
      },
      process: {
        pid: Process.pid,
        platform: RUBY_PLATFORM,
        cpus: Etc.nprocessors,
        memoryMB: rss_mb,
        uptimeSeconds: (Process.clock_gettime(Process::CLOCK_MONOTONIC) - STARTED_AT).floor
      },
      request: {
        method: request.request_method,
        host: request.host_with_port,
        clientIp: request.env['REMOTE_ADDR'],
        forwardedFor: request.env['HTTP_X_FORWARDED_FOR'],
        forwardedProto: request.env['HTTP_X_FORWARDED_PROTO'],
        userAgent: request.user_agent
      },
      requests: LOCK.synchronize { STATE[:requests] },
      probes: probes,
      env: app_env,
      time: Time.now.utc.iso8601
    }.to_json
  end

  get '/health' do
    content_type :json
    ok = probes['live']
    status(ok ? 200 : 503)
    { status: ok ? 'UP' : 'DOWN' }.to_json
  end

  get '/ready' do
    content_type :json
    ok = probes['ready']
    status(ok ? 200 : 503)
    { status: ok ? 'UP' : 'DOWN' }.to_json
  end

  post %r{/api/fail/(live|ready)} do |name|
    content_type :json
    seconds = seconds_param(60)
    LOCK.synchronize { STATE[:fail_until][name] = Time.now.to_f + seconds }
    warn "#{name} probe failing for #{seconds}s"
    { probe: name, seconds: seconds, probes: probes }.to_json
  end

  post '/api/stress' do
    content_type :json
    seconds = seconds_param(30, 60)
    warn "Burning CPU for #{seconds}s"
    finish = Process.clock_gettime(Process::CLOCK_MONOTONIC) + seconds
    Math.sqrt(rand) while Process.clock_gettime(Process::CLOCK_MONOTONIC) < finish
    { seconds: seconds, pod: Socket.gethostname }.to_json
  end
end
