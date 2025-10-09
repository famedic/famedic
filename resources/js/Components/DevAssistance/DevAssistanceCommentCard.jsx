import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { Button } from "@/Components/Catalyst/button";
import { Code, Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	UserCircleIcon,
	ChevronDownIcon,
	CommandLineIcon,
} from "@heroicons/react/16/solid";
import Card from "@/Components/Card";

export default function DevAssistanceCommentCard({ comment }) {
	return (
		<Card className="space-y-3 overflow-hidden p-3">
			<CommentHeader comment={comment} />
			<CommentContent comment={comment} />
		</Card>
	);
}

function CommentHeader({ comment }) {
	return (
		<div className="flex flex-wrap items-center justify-between gap-2">
			<Badge
				color="slate"
				className="text-famedic-darker dark:text-white"
			>
				{comment.administrator_id ? (
					<>
						<UserCircleIcon className="size-4 fill-slate-400 dark:fill-slate-500" />
						{comment.administrator.user.name}
					</>
				) : (
					<>
						<CommandLineIcon className="size-4 fill-slate-400 dark:fill-slate-500" />
						Equipo de desarrollo
					</>
				)}
			</Badge>
			<Code className="text-xs !text-slate-500 dark:!text-slate-400">
				{comment.formatted_created_at}
			</Code>
		</div>
	);
}

function CommentContent({ comment }) {
	const isLong = comment.comment.length > 1000;

	if (isLong) {
		return (
			<Disclosure>
				{({ open }) => (
					<>
						<DisclosurePanel
							static
							className={open ? undefined : "line-clamp-6"}
						>
							<Text className="whitespace-pre-wrap break-words !leading-none !text-slate-500 dark:!text-slate-300">
								{comment.comment}
							</Text>
						</DisclosurePanel>
						<DisclosureButton as={Button} outline>
							{open ? (
								<>
									Ver menos
									<ChevronDownIcon className="rotate-180" />
								</>
							) : (
								<>
									Ver más
									<ChevronDownIcon />
								</>
							)}
						</DisclosureButton>
					</>
				)}
			</Disclosure>
		);
	}

	return (
		<Text className="whitespace-pre-wrap break-words !leading-none !text-slate-500 dark:!text-slate-300">
			{comment.comment}
		</Text>
	);
}
