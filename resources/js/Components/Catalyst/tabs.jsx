import { Tab as HeadlessTab, TabGroup as HeadlessTabGroup, TabList as HeadlessTabList, TabPanel as HeadlessTabPanel, TabPanels as HeadlessTabPanels } from '@headlessui/react'
import clsx from 'clsx'

export function TabGroup({ children, selectedIndex, onChange, ...props }) {
    return (
        <HeadlessTabGroup selectedIndex={selectedIndex} onChange={onChange} {...props}>
            {children}
        </HeadlessTabGroup>
    )
}

export function TabList({ children, className, ...props }) {
    return (
        <HeadlessTabList className={clsx('flex', className)} {...props}>
            {children}
        </HeadlessTabList>
    )
}

export function Tab({ children, className, ...props }) {
    return (
        <HeadlessTab className={clsx('cursor-pointer outline-none', className)} {...props}>
            {({ selected }) => children(selected)}
        </HeadlessTab>
    )
}

export function TabPanels({ children, ...props }) {
    return <HeadlessTabPanels {...props}>{children}</HeadlessTabPanels>
}

export function TabPanel({ children, ...props }) {
    return <HeadlessTabPanel {...props}>{children}</HeadlessTabPanel>
}