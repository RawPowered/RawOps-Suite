// Fix: Removed file content markers that were causing syntax errors.
import React, { useState } from 'react';
import { NAV_LINKS, CATEGORIES, MEGA_MENU_COLLECTIONS, MEGA_MENU_FEATURED_PRODUCT } from '../constants';

const Header: React.FC = () => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [isShopMenuOpen, setIsShopMenuOpen] = useState(false);
  const [isMobileShopOpen, setIsMobileShopOpen] = useState(false);

  return (
    <header className="bg-white/80 backdrop-blur-sm sticky top-0 z-50 border-b border-gray-200">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-20">
          <div className="flex items-center">
            <a href="#" className="text-2xl font-bold text-blue-600">
              Product
            </a>
          </div>
          <div className="hidden md:flex items-center space-x-8">
            {NAV_LINKS.map((link) => (
              link.name === 'Shop' ? (
                <div 
                  key={link.name}
                  className="relative"
                  onMouseEnter={() => setIsShopMenuOpen(true)}
                  onMouseLeave={() => setIsShopMenuOpen(false)}
                >
                  <a href={link.href} className="text-gray-600 hover:text-blue-600 transition-colors duration-200 font-medium flex items-center py-5">
                    {link.name}
                    <i className={`fas fa-chevron-down ml-2 text-xs transition-transform duration-200 ${isShopMenuOpen ? 'rotate-180' : ''}`}></i>
                  </a>
                  {isShopMenuOpen && (
                    <div className="absolute top-full left-1/2 -translate-x-1/2 pt-4 w-screen max-w-4xl">
                      <div className="bg-white rounded-lg shadow-2xl border border-gray-100 grid grid-cols-3 gap-6 p-8">
                        {/* Categories Column */}
                        <div className="col-span-1">
                          <h3 className="text-sm font-semibold tracking-wider text-gray-500 uppercase">Shop by Category</h3>
                          <ul className="mt-4 space-y-3">
                            {CATEGORIES.map(cat => (
                              <li key={cat.name}>
                                <a href={cat.href} className="flex items-center p-2 -ml-2 rounded-md hover:bg-gray-100 transition-colors">
                                  <i className={`fas ${cat.icon} text-blue-600 w-6 text-center`}></i>
                                  <span className="ml-3 font-medium text-gray-800">{cat.name}</span>
                                </a>
                              </li>
                            ))}
                          </ul>
                        </div>
                        {/* Brands & Featured Product Column */}
                        <div className="col-span-2 grid grid-cols-2 gap-6">
                            <div>
                               <h3 className="text-sm font-semibold tracking-wider text-gray-500 uppercase">Shop Collections</h3>
                               <ul className="mt-4 space-y-3">
                                {MEGA_MENU_COLLECTIONS.map(collection => (
                                    <li key={collection.name}>
                                        <a href={collection.href} className="block p-2 -ml-2 rounded-md hover:bg-gray-100 transition-colors">
                                            <p className="font-medium text-gray-800">{collection.name}</p>
                                            <p className="text-sm text-gray-500">{collection.description}</p>
                                        </a>
                                    </li>
                                ))}
                               </ul>
                            </div>
                            <div>
                               <h3 className="text-sm font-semibold tracking-wider text-gray-500 uppercase">New Arrival</h3>
                               <a href="#" className="block mt-4 group">
                                 <div className="relative rounded-lg overflow-hidden h-48">
                                    <img src={MEGA_MENU_FEATURED_PRODUCT.imageUrl} alt={MEGA_MENU_FEATURED_PRODUCT.name} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                                    <div className="absolute inset-0 bg-black/30"></div>
                                 </div>
                                 <p className="font-semibold text-gray-800 mt-3">{MEGA_MENU_FEATURED_PRODUCT.name}</p>
                                 <p className="text-sm text-blue-600 font-medium">Shop Now <i className="fas fa-arrow-right ml-1"></i></p>
                               </a>
                            </div>
                        </div>

                      </div>
                    </div>
                  )}
                </div>
              ) : (
                <a key={link.name} href={link.href} className="text-gray-600 hover:text-blue-600 transition-colors duration-200 font-medium">
                  {link.name}
                </a>
              )
            ))}
          </div>
          <div className="hidden md:flex items-center space-x-4">
            <a href="#" className="border border-gray-300 text-gray-700 hover:bg-gray-100 font-semibold px-4 py-2 rounded-md transition-all duration-200 text-sm">
              Login
            </a>
          </div>
          <div className="md:hidden flex items-center">
            <button onClick={() => setIsMenuOpen(!isMenuOpen)} className="text-gray-600 hover:text-blue-600 focus:outline-none">
              <i className={`fas ${isMenuOpen ? 'fa-times' : 'fa-bars'} text-2xl`}></i>
            </button>
          </div>
        </div>
      </div>
      {isMenuOpen && (
        <div className="md:hidden bg-white border-t border-gray-200">
          <div className="px-2 pt-2 pb-3 space-y-1 sm:px-3">
            {NAV_LINKS.map((link) => (
              link.name === 'Shop' ? (
                <div key={link.name}>
                    <button onClick={() => setIsMobileShopOpen(!isMobileShopOpen)} className="w-full flex justify-between items-center px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-blue-600 hover:bg-gray-50">
                        <span>{link.name}</span>
                        <i className={`fas fa-chevron-down text-sm transition-transform ${isMobileShopOpen ? 'rotate-180' : ''}`}></i>
                    </button>
                    {isMobileShopOpen && (
                        <div className="pl-6 mt-1 space-y-1">
                            {CATEGORIES.map(cat => (
                                <a key={cat.name} href={cat.href} className="block px-3 py-2 rounded-md text-base font-medium text-gray-600 hover:text-blue-600 hover:bg-gray-50">
                                    {cat.name}
                                </a>
                            ))}
                            <a href="#" className="block px-3 py-2 rounded-md text-base font-medium text-gray-600 hover:text-blue-600 hover:bg-gray-50">
                                Modern Herbs+
                            </a>
                        </div>
                    )}
                </div>
              ) : (
                <a key={link.name} href={link.href} className="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-blue-600 hover:bg-gray-50">
                    {link.name}
                </a>
              )
            ))}
          </div>
          <div className="pt-4 pb-3 border-t border-gray-200">
            <div className="px-5 space-y-3">
              <a href="#" className="block w-full text-center border border-gray-300 text-gray-700 hover:bg-gray-100 font-semibold px-4 py-2 rounded-md transition-all duration-200 text-sm">
                Login
              </a>
            </div>
          </div>
        </div>
      )}
    </header>
  );
};

export default Header;
