<?php namespace Clockwork\DataSource;

use Clockwork\Helpers\Serializer;
use Clockwork\Helpers\StackTrace;
use Clockwork\Request\Request;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;

// Data source for Laravel notifications and mail components, provides sent notifications and emails
class LaravelNotificationsDataSource extends DataSource
{
	// Event dispatcher
	protected $dispatcher;

	// Sent notifications
	protected $notifications = [];

	// Create a new data source instance, takes an event dispatcher as argument
	public function __construct(Dispatcher $dispatcher)
	{
		$this->dispatcher = $dispatcher;
	}

	// Start listening to the events
	public function listenToEvents()
	{
		$this->dispatcher->listen(MessageSent::class, function ($event) { $this->registerMessage($event); });
		$this->dispatcher->listen(NotificationSent::class, function ($event) { $this->registerNotification($event); });
	}

	// Add sent notifications to the request
	public function resolve(Request $request)
	{
		$request->notifications = array_merge($request->notifications, $this->notifications);

		return $request;
	}

	// Reset the data source to an empty state, clearing any collected data
	public function reset()
	{
		$this->notifications = [];
	}

	// Register a sent email
	protected function registerMessage($event)
	{
		$trace = StackTrace::get()->resolveViewName();

		$mailable = ($frame = $trace->first(function ($frame) { return is_subclass_of($frame->object, Mailable::class); }))
			? $frame->object : null;

		$notification = [
			'subject' => $event->message->getSubject(),
			'from'    => $this->messageAddressToString($event->message->getFrom()),
			'to'      => $this->messageAddressToString($event->message->getTo()),
			'content' => $event->message->getBody(),
			'type'    => 'mail',
			'data'    => [
				'cc'       => $this->messageAddressToString($event->message->getCc()),
				'bcc'      => $this->messageAddressToString($event->message->getBcc()),
				'replyTo'  => $this->messageAddressToString($event->message->getReplyTo()),
				'mailable' => (new Serializer)->normalize($mailable)
			],
			'time'    => microtime(true),
			'trace'   => $shortTrace = (new Serializer)->trace($trace),
			'file'    => isset($shortTrace[0]) ? $shortTrace[0]['file'] : null,
			'line'    => isset($shortTrace[0]) ? $shortTrace[0]['line'] : null
		];

		if ($this->passesFilters([ $notification ])) {
			$this->notifications[] = $notification;
		}
	}

	// Register a sent notification
	protected function registerNotification($event)
	{
		$trace = StackTrace::get()->resolveViewName();

		$channelSpecific = $this->resolveChannelSpecific($event);

		$notification = [
			'subject' => $channelSpecific['subject'],
			'from'    => $channelSpecific['from'],
			'to'      => $channelSpecific['to'],
			'content' => $channelSpecific['content'],
			'type'    => $event->channel,
			'data'    => (new Serializer)->normalizeEach(array_merge($channelSpecific['data'], [
				'notification' => $event->notification,
				'notifiable'   => $event->notifiable,
				'response'     => $event->response
			])),
			'time'    => microtime(true),
			'trace'   => $shortTrace = (new Serializer)->trace($trace),
			'file'    => isset($shortTrace[0]) ? $shortTrace[0]['file'] : null,
			'line'    => isset($shortTrace[0]) ? $shortTrace[0]['line'] : null
		];

		if ($event->channel == 'mail') {
			if ($this->updateLastEmailNotification($notification)) return;
		}

		if ($this->passesFilters([ $notification ])) {
			$this->notifications[] = $notification;
		}
	}

	// Update last sent email notification with additional data from the notification event
	protected function updateLastEmailNotification($notification)
	{
		$lastIndex = count($this->notifications) - 1;
		$lastNotification = $this->notifications[$lastIndex];

		if (implode($lastNotification['to']) != implode($notification['to'])) return false;

		$this->notifications[$lastIndex]['data'] = array_merge($lastNotification['data'], $notification['data']);

		return true;
	}

	// Resolve notification channnel specific data
	protected function resolveChannelSpecific($event)
	{
		if (method_exists($event->notification, 'toMail')) {
			$channelSpecific = $this->resolveMailChannelSpecific($event, $event->notification->toMail($event->notifiable));
		} elseif (method_exists($event->notification, 'toSlack')) {
			$channelSpecific = $this->resolveSlackChannelSpecific($event, $event->notification->toSlack($event->notifiable));
		} elseif (method_exists($event->notification, 'toNexmo')) {
			$channelSpecific = $this->resolveNexmoChannelSpecific($event, $event->notification->toNexmo($event->notifiable));
		} elseif (method_exists($event->notification, 'toBroadcast')) {
			$channelSpecific = [ 'data' => $event->notification->toBroadcast($event->notifiable)->data ];
		} else {
			$channelSpecific = [ 'data' => $event->notification->toArray() ];
		}

		return array_merge(
			[ 'subject' => null, 'from' => null, 'to' => null, 'content' => null, 'data' => [] ], $channelSpecific
		);
	}

	// Resolve mail notification channel specific data
	protected function resolveMailChannelSpecific($event, $message)
	{
		return [
			'subject' => $message->subject ?: get_class($event->notification),
			'from'    => $this->notificationAddressToString($message->from),
			'to'      => $this->notificationAddressToString($event->notifiable->routeNotificationFor('mail', $event->notification)),
			'data'    => [
				'cc'      => $this->notificationAddressToString($message->cc),
				'bcc'     => $this->notificationAddressToString($message->bcc),
				'replyTo' => $this->notificationAddressToString($message->replyTo)
			]
		];
	}

	// Resolve Slack notification channel specific data
	protected function resolveSlackChannelSpecific($event, $message)
	{
		return [
			'subject' => $message->subject ?: get_class($event->notification),
			'from'    => $message->username,
			'to'      => $message->channel,
			'content' => $message->content
		];
	}

	// Resolve Nexmo notification channel specific data
	protected function resolveNexmoChannelSpecific($event, $message)
	{
		return [
			'subject' => $message->subject ?: get_class($event->notification),
			'from'    => $message->from,
			'to'      => $event->notifiable->routeNotificationFor('nexmo', $event->notification),
			'content' => $message->content
		];
	}

	protected function messageAddressToString($address)
	{
		if (! $address) return;

		return array_map(function ($name, $email) {
			return $name ? "{$name} <{$email}>" : $email;
		}, $address, array_keys($address));
	}

	protected function notificationAddressToString($address)
	{
		if (! $address) return;
		if (! is_array($address)) $address = [ $address ];

		return array_map(function ($address) {
			if (! is_array($address)) return $address;

			return $address[1] ? "{$address[1]} <{$address[0]}>" : $address[0];
		}, $address);
	}
}
